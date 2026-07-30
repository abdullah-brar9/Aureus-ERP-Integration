<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Accounting\Services\Currency\IsoCurrencySynchronizer;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendCurrencyMaster();
        $this->extendCompanies();
        $this->createCompanyCurrencies();
        $this->createExchangeRates();
        $this->createAccountDetails();
        $this->extendBankStatements();
        $this->extendBankStatementLines();
        $this->extendBankMappings();
        $this->extendBankRulesAndTransfers();
        $this->extendMoves();
        $this->extendMoveLines();
        $this->createFxRevaluations();
        $this->addPerformanceIndexes();
        $this->backfillEvidenceBasedCurrencyData();
        app(IsoCurrencySynchronizer::class)->synchronize();
        app(AccountingPermissionRegistrar::class)->synchronize();
    }

    public function down(): void {}

    private function extendCurrencyMaster(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (! Schema::hasColumn('currencies', 'code')) {
                $table->string('code', 3)->nullable()->after('name');
            }
            if (! Schema::hasColumn('currencies', 'is_iso_fiat')) {
                $table->boolean('is_iso_fiat')->default(false)->after('active');
            }
            if (! Schema::hasColumn('currencies', 'display_order')) {
                $table->unsignedInteger('display_order')->default(1000)->after('is_iso_fiat');
            }
        });

        $groups = DB::table('currencies')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (object $currency): bool => preg_match('/^[A-Za-z]{3}$/', (string) $currency->name) === 1)
            ->groupBy(fn (object $currency): string => mb_strtoupper((string) $currency->name));

        foreach ($groups as $code => $records) {
            $canonical = $records
                ->sort(function (object $left, object $right): int {
                    $referenceComparison = $this->currencyReferenceCount((int) $right->id)
                        <=> $this->currencyReferenceCount((int) $left->id);

                    return $referenceComparison !== 0
                        ? $referenceComparison
                        : ((int) $left->id <=> (int) $right->id);
                })
                ->first();

            DB::table('currencies')->where('id', $canonical->id)->update(['code' => $code]);
            DB::table('currencies')
                ->whereIn('id', $records->pluck('id')->reject(fn (int $id): bool => $id === (int) $canonical->id))
                ->update(['code' => null, 'active' => false, 'is_iso_fiat' => false]);
        }

        if (! Schema::hasIndex('currencies', 'currencies_code_unique')) {
            Schema::table('currencies', fn (Blueprint $table) => $table->unique('code', 'currencies_code_unique'));
        }
    }

    private function currencyReferenceCount(int $currencyId): int
    {
        $references = [
            'companies'                     => 'currency_id',
            'countries'                     => 'currency_id',
            'currency_rates'                => 'currency_id',
            'accounts_accounts'             => 'currency_id',
            'accounts_journals'             => 'currency_id',
            'accounts_account_moves'        => 'currency_id',
            'accounts_account_move_lines'   => 'currency_id',
            'accounts_bank_statements'      => 'currency_id',
            'accounts_bank_statement_lines' => 'currency_id',
        ];

        return collect($references)->sum(function (string $column, string $table) use ($currencyId): int {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }

            return DB::table($table)->where($column, $currencyId)->count();
        });
    }

    private function extendCompanies(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'fx_gain_account_id')) {
                $table->foreignId('fx_gain_account_id')->nullable()->after('currency_id')->constrained('accounts_accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('companies', 'fx_loss_account_id')) {
                $table->foreignId('fx_loss_account_id')->nullable()->after('fx_gain_account_id')->constrained('accounts_accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('companies', 'rate_source_priority')) {
                $table->json('rate_source_priority')->nullable()->after('fx_loss_account_id');
            }
            if (! Schema::hasColumn('companies', 'allow_previous_rate_fallback')) {
                $table->boolean('allow_previous_rate_fallback')->default(false)->after('rate_source_priority');
            }
            if (! Schema::hasColumn('companies', 'pnl_translation_policy')) {
                $table->string('pnl_translation_policy')->default('transaction_date')->after('allow_previous_rate_fallback');
            }
            if (! Schema::hasColumn('companies', 'balance_sheet_translation_policy')) {
                $table->string('balance_sheet_translation_policy')->default('period_closing')->after('pnl_translation_policy');
            }
        });
    }

    private function createCompanyCurrencies(): void
    {
        if (Schema::hasTable('accounting_company_currencies')) {
            return;
        }

        Schema::create('accounting_company_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->boolean('transaction_enabled')->default(true);
            $table->boolean('reporting_enabled')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'currency_id'], 'company_currencies_company_currency_unique');
            $table->index(['company_id', 'transaction_enabled', 'currency_id'], 'company_currencies_transaction_index');
            $table->index(['company_id', 'reporting_enabled', 'currency_id'], 'company_currencies_reporting_index');
        });
    }

    private function createExchangeRates(): void
    {
        if (Schema::hasTable('accounting_exchange_rates')) {
            return;
        }

        Schema::create('accounting_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('source_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('target_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('effective_date');
            $table->decimal('rate', 30, 15);
            $table->string('rate_type');
            $table->string('source');
            $table->string('approval_status')->default('draft');
            $table->string('source_reference')->nullable();
            $table->string('provider')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('audit_metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['company_id', 'source_currency_id', 'target_currency_id', 'approval_status', 'rate_type', 'effective_date'],
                'exchange_rates_approved_lookup_index',
            );
            $table->index(['company_id', 'effective_date'], 'exchange_rates_company_date_index');
            $table->index(['company_id', 'approval_status', 'effective_date'], 'exchange_rates_company_status_date_index');
        });
    }

    private function createAccountDetails(): void
    {
        if (Schema::hasTable('accounting_account_details')) {
            return;
        }

        Schema::create('accounting_account_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('accounts_accounts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('nature')->nullable();
            foreach (range(1, 7) as $level) {
                $table->string("classification_{$level}")->nullable();
            }
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('branch_reference')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'bank_account_number'], 'account_details_company_bank_number_unique');
            $table->unique(['company_id', 'iban'], 'account_details_company_iban_unique');
            $table->index(['company_id', 'currency_id'], 'account_details_company_currency_index');
        });
    }

    private function extendBankStatements(): void
    {
        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_bank_statements', 'company_currency_id')) {
                $table->foreignId('company_currency_id')->nullable()->after('currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'detected_currency_code')) {
                $table->string('detected_currency_code', 3)->nullable()->after('company_currency_id');
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'currency_was_overridden')) {
                $table->boolean('currency_was_overridden')->default(false)->after('detected_currency_code');
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'company_opening_balance')) {
                $table->decimal('company_opening_balance', 20, 4)->nullable()->after('closing_balance');
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'company_total_debits')) {
                $table->decimal('company_total_debits', 20, 4)->nullable()->after('company_opening_balance');
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'company_total_credits')) {
                $table->decimal('company_total_credits', 20, 4)->nullable()->after('company_total_debits');
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'company_closing_balance')) {
                $table->decimal('company_closing_balance', 20, 4)->nullable()->after('company_total_credits');
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'conversion_status')) {
                $table->string('conversion_status')->default('pending')->after('company_closing_balance');
            }
        });

        if (Schema::hasIndex('accounts_bank_statements', 'bank_statements_company_file_parser_unique')) {
            Schema::table('accounts_bank_statements', fn (Blueprint $table) => $table->dropUnique('bank_statements_company_file_parser_unique'));
        }
        if (! Schema::hasIndex('accounts_bank_statements', 'bank_statements_source_scope_unique')) {
            Schema::table('accounts_bank_statements', function (Blueprint $table) {
                $table->unique(
                    ['company_id', 'bank_account_number', 'currency_id', 'statement_start_date', 'statement_end_date', 'file_hash', 'parser'],
                    'bank_statements_source_scope_unique',
                );
            });
        }
        if (! Schema::hasIndex('accounts_bank_statements', 'bank_statements_company_currency_period_index')) {
            Schema::table('accounts_bank_statements', fn (Blueprint $table) => $table->index(
                ['company_id', 'currency_id', 'statement_start_date', 'statement_end_date'],
                'bank_statements_company_currency_period_index',
            ));
        }
    }

    private function extendBankStatementLines(): void
    {
        Schema::table('accounts_bank_statement_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'original_currency_id')) {
                $table->foreignId('original_currency_id')->nullable()->after('currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'company_currency_id')) {
                $table->foreignId('company_currency_id')->nullable()->after('original_currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'original_debit')) {
                $table->decimal('original_debit', 20, 4)->nullable()->after('credit');
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'original_credit')) {
                $table->decimal('original_credit', 20, 4)->nullable()->after('original_debit');
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'original_signed_amount')) {
                $table->decimal('original_signed_amount', 20, 4)->nullable()->after('original_credit');
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'company_debit')) {
                $table->decimal('company_debit', 20, 4)->nullable()->after('original_signed_amount');
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'company_credit')) {
                $table->decimal('company_credit', 20, 4)->nullable()->after('company_debit');
            }
            if (! Schema::hasColumn('accounts_bank_statement_lines', 'company_signed_amount')) {
                $table->decimal('company_signed_amount', 20, 4)->nullable()->after('company_credit');
            }
            $this->addRateSnapshotColumns($table, 'accounts_bank_statement_lines', 'company_signed_amount');
        });
    }

    private function extendBankMappings(): void
    {
        Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_bank_transaction_mappings', 'suggestion_explanation')) {
                $table->text('suggestion_explanation')->nullable()->after('confidence');
            }
            if (! Schema::hasColumn('accounting_bank_transaction_mappings', 'original_currency_id')) {
                $table->unsignedBigInteger('original_currency_id')->nullable()->after('statement_line_id');
                $table->foreign('original_currency_id', 'bank_mappings_original_currency_fk')->references('id')->on('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounting_bank_transaction_mappings', 'company_currency_id')) {
                $table->unsignedBigInteger('company_currency_id')->nullable()->after('original_currency_id');
                $table->foreign('company_currency_id', 'bank_mappings_company_currency_fk')->references('id')->on('currencies')->nullOnDelete();
            }
            $this->addRateSnapshotColumns($table, 'accounting_bank_transaction_mappings', 'company_currency_id');
        });
    }

    private function extendBankRulesAndTransfers(): void
    {
        Schema::table('accounting_bank_mapping_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_bank_mapping_rules', 'currency_id')) {
                $table->foreignId('currency_id')->nullable()->after('company_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounting_bank_mapping_rules', 'normalized_description')) {
                $table->string('normalized_description')->nullable()->after('description_pattern');
            }
            if (! Schema::hasColumn('accounting_bank_mapping_rules', 'explanation')) {
                $table->text('explanation')->nullable()->after('confidence');
            }
        });

        if (! Schema::hasIndex('accounting_bank_mapping_rules', 'bank_rules_company_currency_active_priority_index')) {
            Schema::table('accounting_bank_mapping_rules', fn (Blueprint $table) => $table->index(
                ['company_id', 'currency_id', 'is_active', 'priority'],
                'bank_rules_company_currency_active_priority_index',
            ));
        }

        Schema::table('accounting_bank_transfer_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'outgoing_currency_id')) {
                $table->foreignId('outgoing_currency_id')->nullable()->after('incoming_statement_line_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'incoming_currency_id')) {
                $table->foreignId('incoming_currency_id')->nullable()->after('outgoing_currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'outgoing_amount')) {
                $table->decimal('outgoing_amount', 20, 4)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'incoming_amount')) {
                $table->decimal('incoming_amount', 20, 4)->nullable()->after('outgoing_amount');
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'company_amount')) {
                $table->decimal('company_amount', 20, 4)->nullable()->after('incoming_amount');
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'fx_difference')) {
                $table->decimal('fx_difference', 20, 4)->nullable()->after('company_amount');
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'bank_charge_amount')) {
                $table->decimal('bank_charge_amount', 20, 4)->nullable()->after('fx_difference');
            }
            if (! Schema::hasColumn('accounting_bank_transfer_matches', 'bank_charge_account_id')) {
                $table->foreignId('bank_charge_account_id')->nullable()->after('bank_charge_amount')->constrained('accounts_accounts')->nullOnDelete();
            }
        });
    }

    private function extendMoves(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_account_moves', 'original_currency_id')) {
                $table->foreignId('original_currency_id')->nullable()->after('currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_account_moves', 'company_currency_id')) {
                $table->foreignId('company_currency_id')->nullable()->after('original_currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_account_moves', 'reporting_currency_id')) {
                $table->foreignId('reporting_currency_id')->nullable()->after('company_currency_id')->constrained('currencies')->nullOnDelete();
            }
            $this->addRateSnapshotColumns($table, 'accounts_account_moves', 'reporting_currency_id');
        });
    }

    private function extendMoveLines(): void
    {
        Schema::table('accounts_account_move_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_account_move_lines', 'original_currency_id')) {
                $table->foreignId('original_currency_id')->nullable()->after('currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'reporting_currency_id')) {
                $table->foreignId('reporting_currency_id')->nullable()->after('original_currency_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'original_debit')) {
                $table->decimal('original_debit', 20, 4)->nullable()->after('credit');
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'original_credit')) {
                $table->decimal('original_credit', 20, 4)->nullable()->after('original_debit');
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'original_signed_amount')) {
                $table->decimal('original_signed_amount', 20, 4)->nullable()->after('original_credit');
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'company_debit')) {
                $table->decimal('company_debit', 20, 4)->nullable()->after('original_signed_amount');
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'company_credit')) {
                $table->decimal('company_credit', 20, 4)->nullable()->after('company_debit');
            }
            if (! Schema::hasColumn('accounts_account_move_lines', 'company_signed_amount')) {
                $table->decimal('company_signed_amount', 20, 4)->nullable()->after('company_credit');
            }
            $this->addRateSnapshotColumns($table, 'accounts_account_move_lines', 'company_signed_amount');
        });
    }

    private function addRateSnapshotColumns(Blueprint $table, string $tableName, string $after): void
    {
        if (! Schema::hasColumn($tableName, 'exchange_rate_id')) {
            $table->foreignId('exchange_rate_id')->nullable()->after($after)->constrained('accounting_exchange_rates')->nullOnDelete();
        }
        if (! Schema::hasColumn($tableName, 'exchange_rate')) {
            $table->decimal('exchange_rate', 30, 15)->nullable()->after('exchange_rate_id');
        }
        if (! Schema::hasColumn($tableName, 'rate_date')) {
            $table->date('rate_date')->nullable()->after('exchange_rate');
        }
        if (! Schema::hasColumn($tableName, 'rate_source')) {
            $table->string('rate_source')->nullable()->after('rate_date');
        }
        if (! Schema::hasColumn($tableName, 'rate_type')) {
            $table->string('rate_type')->nullable()->after('rate_source');
        }
        if (! Schema::hasColumn($tableName, 'conversion_status')) {
            $table->string('conversion_status')->default('pending')->after('rate_type');
        }
    }

    private function createFxRevaluations(): void
    {
        if (Schema::hasTable('accounting_fx_revaluations')) {
            return;
        }

        Schema::create('accounting_fx_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('period_end');
            $table->foreignId('exchange_rate_id')->constrained('accounting_exchange_rates')->restrictOnDelete();
            $table->foreignId('move_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
            $table->foreignId('reversal_move_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->decimal('original_balance', 20, 4)->default(0);
            $table->decimal('book_company_balance', 20, 4)->default(0);
            $table->decimal('revalued_company_balance', 20, 4)->default(0);
            $table->decimal('difference', 20, 4)->default(0);
            $table->date('reversal_date')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'currency_id', 'period_end'], 'fx_revaluations_company_currency_period_unique');
            $table->index(['company_id', 'status', 'period_end'], 'fx_revaluations_company_status_period_index');
        });
    }

    private function addPerformanceIndexes(): void
    {
        if (! Schema::hasIndex('accounts_account_companies', 'account_companies_account_company_unique')) {
            $duplicates = DB::table('accounts_account_companies')
                ->select(['account_id', 'company_id'])
                ->groupBy(['account_id', 'company_id'])
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicates) {
                throw new RuntimeException('Duplicate account/company links must be reviewed before the multi-currency migration can add its safety constraint.');
            }

            Schema::table('accounts_account_companies', fn (Blueprint $table) => $table->unique(
                ['account_id', 'company_id'],
                'account_companies_account_company_unique',
            ));
        }

        if (! Schema::hasIndex('accounts_account_moves', 'account_moves_company_state_date_journal_index')) {
            Schema::table('accounts_account_moves', fn (Blueprint $table) => $table->index(
                ['company_id', 'state', 'date', 'journal_id'],
                'account_moves_company_state_date_journal_index',
            ));
        }
        if (! Schema::hasIndex('accounts_account_move_lines', 'move_lines_company_date_currency_account_index')) {
            Schema::table('accounts_account_move_lines', fn (Blueprint $table) => $table->index(
                ['company_id', 'date', 'currency_id', 'account_id'],
                'move_lines_company_date_currency_account_index',
            ));
        }
        if (! Schema::hasIndex('accounts_bank_statement_lines', 'bank_lines_company_conversion_date_index')) {
            Schema::table('accounts_bank_statement_lines', fn (Blueprint $table) => $table->index(
                ['company_id', 'conversion_status', 'transaction_date'],
                'bank_lines_company_conversion_date_index',
            ));
        }
    }

    private function backfillEvidenceBasedCurrencyData(): void
    {
        DB::table('companies')
            ->whereNotNull('currency_id')
            ->orderBy('id')
            ->chunkById(100, function ($companies): void {
                foreach ($companies as $company) {
                    DB::table('accounting_company_currencies')->updateOrInsert(
                        ['company_id' => $company->id, 'currency_id' => $company->currency_id],
                        ['transaction_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            });

        DB::statement(<<<'SQL'
            UPDATE accounts_bank_statements statements
            JOIN companies ON companies.id = statements.company_id
            SET statements.company_currency_id = companies.currency_id,
                statements.company_opening_balance = CASE WHEN statements.currency_id = companies.currency_id THEN statements.opening_balance ELSE NULL END,
                statements.company_total_debits = CASE WHEN statements.currency_id = companies.currency_id THEN statements.total_debits ELSE NULL END,
                statements.company_total_credits = CASE WHEN statements.currency_id = companies.currency_id THEN statements.total_credits ELSE NULL END,
                statements.company_closing_balance = CASE WHEN statements.currency_id = companies.currency_id THEN statements.closing_balance ELSE NULL END,
                statements.conversion_status = CASE WHEN statements.currency_id = companies.currency_id THEN 'complete' ELSE 'review_required' END
        SQL);

        DB::statement(<<<'SQL'
            UPDATE accounts_bank_statement_lines bank_lines
            JOIN companies ON companies.id = bank_lines.company_id
            SET bank_lines.original_currency_id = bank_lines.currency_id,
                bank_lines.company_currency_id = companies.currency_id,
                bank_lines.original_debit = bank_lines.debit,
                bank_lines.original_credit = bank_lines.credit,
                bank_lines.original_signed_amount = bank_lines.credit - bank_lines.debit,
                bank_lines.company_debit = CASE WHEN bank_lines.currency_id = companies.currency_id THEN bank_lines.debit ELSE NULL END,
                bank_lines.company_credit = CASE WHEN bank_lines.currency_id = companies.currency_id THEN bank_lines.credit ELSE NULL END,
                bank_lines.company_signed_amount = CASE WHEN bank_lines.currency_id = companies.currency_id THEN bank_lines.credit - bank_lines.debit ELSE NULL END,
                bank_lines.exchange_rate = CASE WHEN bank_lines.currency_id = companies.currency_id THEN 1 ELSE NULL END,
                bank_lines.rate_date = CASE WHEN bank_lines.currency_id = companies.currency_id THEN bank_lines.transaction_date ELSE NULL END,
                bank_lines.rate_source = CASE WHEN bank_lines.currency_id = companies.currency_id THEN 'identity' ELSE NULL END,
                bank_lines.rate_type = CASE WHEN bank_lines.currency_id = companies.currency_id THEN 'transaction' ELSE NULL END,
                bank_lines.conversion_status = CASE WHEN bank_lines.currency_id = companies.currency_id THEN 'complete' ELSE 'review_required' END
        SQL);

        DB::statement(<<<'SQL'
            UPDATE accounting_bank_transaction_mappings mappings
            JOIN accounts_bank_statement_lines bank_lines ON bank_lines.id = mappings.statement_line_id
            SET mappings.original_currency_id = bank_lines.original_currency_id,
                mappings.company_currency_id = bank_lines.company_currency_id,
                mappings.exchange_rate_id = bank_lines.exchange_rate_id,
                mappings.exchange_rate = bank_lines.exchange_rate,
                mappings.rate_date = bank_lines.rate_date,
                mappings.rate_source = bank_lines.rate_source,
                mappings.rate_type = bank_lines.rate_type,
                mappings.conversion_status = bank_lines.conversion_status
        SQL);

        DB::statement(<<<'SQL'
            UPDATE accounts_account_moves moves
            JOIN companies ON companies.id = moves.company_id
            SET moves.original_currency_id = moves.currency_id,
                moves.company_currency_id = companies.currency_id,
                moves.exchange_rate = CASE WHEN moves.currency_id = companies.currency_id THEN 1 ELSE NULL END,
                moves.rate_date = CASE WHEN moves.currency_id = companies.currency_id THEN moves.date ELSE NULL END,
                moves.rate_source = CASE WHEN moves.currency_id = companies.currency_id THEN 'identity' ELSE NULL END,
                moves.rate_type = CASE WHEN moves.currency_id = companies.currency_id THEN 'transaction' ELSE NULL END,
                moves.conversion_status = CASE WHEN moves.currency_id = companies.currency_id THEN 'complete' ELSE 'review_required' END
        SQL);

        DB::statement(<<<'SQL'
            UPDATE accounts_account_move_lines move_lines
            JOIN companies ON companies.id = move_lines.company_id
            SET move_lines.original_currency_id = move_lines.currency_id,
                move_lines.company_currency_id = COALESCE(move_lines.company_currency_id, companies.currency_id),
                move_lines.original_debit = CASE
                    WHEN move_lines.currency_id = companies.currency_id THEN move_lines.debit
                    WHEN COALESCE(move_lines.amount_currency, 0) > 0 THEN move_lines.amount_currency
                    ELSE 0
                END,
                move_lines.original_credit = CASE
                    WHEN move_lines.currency_id = companies.currency_id THEN move_lines.credit
                    WHEN COALESCE(move_lines.amount_currency, 0) < 0 THEN ABS(move_lines.amount_currency)
                    ELSE 0
                END,
                move_lines.original_signed_amount = CASE
                    WHEN move_lines.currency_id = companies.currency_id THEN move_lines.debit - move_lines.credit
                    ELSE move_lines.amount_currency
                END,
                move_lines.company_debit = move_lines.debit,
                move_lines.company_credit = move_lines.credit,
                move_lines.company_signed_amount = move_lines.debit - move_lines.credit,
                move_lines.exchange_rate = CASE WHEN move_lines.currency_id = companies.currency_id THEN 1 ELSE NULL END,
                move_lines.rate_date = CASE WHEN move_lines.currency_id = companies.currency_id THEN move_lines.date ELSE NULL END,
                move_lines.rate_source = CASE WHEN move_lines.currency_id = companies.currency_id THEN 'identity' ELSE NULL END,
                move_lines.rate_type = CASE WHEN move_lines.currency_id = companies.currency_id THEN 'transaction' ELSE NULL END,
                move_lines.conversion_status = CASE WHEN move_lines.currency_id = companies.currency_id THEN 'complete' ELSE 'review_required' END
        SQL);
    }
};
