<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCoaBatchColumns();

        $this->createCoaSourceRowsTable();

        $this->addBankStatementColumns();

        $this->addBankStatementLineColumns();

        if (! Schema::hasTable('accounting_bank_transfer_matches')) {
            Schema::create('accounting_bank_transfer_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('outgoing_statement_line_id');
                $table->foreignId('incoming_statement_line_id');
                $table->foreignId('reviewer_id')->nullable();
                $table->string('match_reference')->unique();
                $table->decimal('amount', 20, 4);
                $table->decimal('confidence', 5, 4)->default(1);
                $table->string('status')->default('suggested');
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['outgoing_statement_line_id', 'incoming_statement_line_id'], 'bank_transfer_line_pair_unique');
                $table->foreign('company_id', 'bank_transfer_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('outgoing_statement_line_id', 'bank_transfer_outgoing_fk')->references('id')->on('accounts_bank_statement_lines')->cascadeOnDelete();
                $table->foreign('incoming_statement_line_id', 'bank_transfer_incoming_fk')->references('id')->on('accounts_bank_statement_lines')->cascadeOnDelete();
                $table->foreign('reviewer_id', 'bank_transfer_reviewer_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('accounting_bank_mapping_rules')) {
            Schema::create('accounting_bank_mapping_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('bank_gl_account_id')->nullable();
                $table->foreignId('offset_account_id');
                $table->foreignId('creator_id')->nullable();
                $table->string('name');
                $table->string('bank_account_number')->nullable();
                $table->string('description_pattern')->nullable();
                $table->string('reference_pattern')->nullable();
                $table->string('direction')->nullable();
                $table->string('counterparty_pattern')->nullable();
                $table->decimal('minimum_amount', 20, 4)->nullable();
                $table->decimal('maximum_amount', 20, 4)->nullable();
                $table->string('transaction_type')->nullable();
                $table->string('tax_treatment')->nullable();
                $table->string('cash_flow_category')->nullable();
                $table->decimal('confidence', 5, 4)->default(0.8);
                $table->unsignedInteger('priority')->default(100);
                $table->unsignedInteger('usage_count')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'is_active', 'priority'], 'bank_rules_company_active_priority_idx');
                $table->foreign('company_id', 'bank_rules_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('bank_gl_account_id', 'bank_rules_bank_account_fk')->references('id')->on('accounts_accounts')->nullOnDelete();
                $table->foreign('offset_account_id', 'bank_rules_offset_account_fk')->references('id')->on('accounts_accounts')->restrictOnDelete();
                $table->foreign('creator_id', 'bank_rules_creator_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('accounting_bank_transaction_mappings')) {
            Schema::create('accounting_bank_transaction_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('statement_line_id');
                $table->foreignId('bank_gl_account_id')->nullable();
                $table->foreignId('offset_account_id')->nullable();
                $table->foreignId('mapping_rule_id')->nullable();
                $table->foreignId('transfer_match_id')->nullable();
                $table->foreignId('reviewer_id')->nullable();
                $table->foreignId('move_id')->nullable();
                $table->string('map_reference')->unique();
                $table->string('transaction_type')->nullable();
                $table->string('counterparty')->nullable();
                $table->string('supporting_document')->nullable();
                $table->string('tax_treatment')->nullable();
                $table->string('review_status')->default('unmapped');
                $table->string('posting_status')->default('not_posted');
                $table->string('cash_flow_category')->nullable();
                $table->decimal('confidence', 5, 4)->default(0);
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->unique('statement_line_id');
                $table->index(['company_id', 'review_status'], 'bank_mappings_company_review_idx');
                $table->index(['company_id', 'posting_status'], 'bank_mappings_company_posting_idx');
                $table->foreign('company_id', 'bank_mappings_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('statement_line_id', 'bank_mappings_line_fk')->references('id')->on('accounts_bank_statement_lines')->cascadeOnDelete();
                $table->foreign('bank_gl_account_id', 'bank_mappings_bank_account_fk')->references('id')->on('accounts_accounts')->nullOnDelete();
                $table->foreign('offset_account_id', 'bank_mappings_offset_account_fk')->references('id')->on('accounts_accounts')->nullOnDelete();
                $table->foreign('mapping_rule_id', 'bank_mappings_rule_fk')->references('id')->on('accounting_bank_mapping_rules')->nullOnDelete();
                $table->foreign('transfer_match_id', 'bank_mappings_transfer_fk')->references('id')->on('accounting_bank_transfer_matches')->nullOnDelete();
                $table->foreign('reviewer_id', 'bank_mappings_reviewer_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('move_id', 'bank_mappings_move_fk')->references('id')->on('accounts_account_moves')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('accounting_manual_adjustments')) {
            Schema::create('accounting_manual_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('journal_id')->nullable();
                $table->foreignId('debit_account_id');
                $table->foreignId('credit_account_id');
                $table->foreignId('reviewer_id')->nullable();
                $table->foreignId('creator_id')->nullable();
                $table->foreignId('move_id')->nullable();
                $table->string('adjustment_reference')->unique();
                $table->date('date');
                $table->decimal('amount', 20, 4);
                $table->text('description');
                $table->string('supporting_reference')->nullable();
                $table->string('tax_treatment')->nullable();
                $table->string('approval_status')->default('draft');
                $table->string('source_classification');
                $table->string('cash_flow_category')->default('Non-cash');
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'date'], 'manual_adjustments_company_date_idx');
                $table->index(['company_id', 'approval_status'], 'manual_adjustments_company_status_idx');
                $table->foreign('company_id', 'manual_adjustments_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('journal_id', 'manual_adjustments_journal_fk')->references('id')->on('accounts_journals')->nullOnDelete();
                $table->foreign('debit_account_id', 'manual_adjustments_debit_fk')->references('id')->on('accounts_accounts')->restrictOnDelete();
                $table->foreign('credit_account_id', 'manual_adjustments_credit_fk')->references('id')->on('accounts_accounts')->restrictOnDelete();
                $table->foreign('reviewer_id', 'manual_adjustments_reviewer_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('creator_id', 'manual_adjustments_creator_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('move_id', 'manual_adjustments_move_fk')->references('id')->on('accounts_account_moves')->nullOnDelete();
            });
        }

        Schema::table('accounts_account_moves', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_account_moves', 'accounting_source_type')) {
                $table->string('accounting_source_type')->nullable()->after('coa_import_batch_id');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'accounting_source_id')) {
                $table->unsignedBigInteger('accounting_source_id')->nullable()->after('accounting_source_type');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'bank_statement_id')) {
                $table->foreignId('bank_statement_id')->nullable()->after('accounting_source_id')->constrained('accounts_bank_statements')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_account_moves', 'bank_mapping_id')) {
                $table->foreignId('bank_mapping_id')->nullable()->after('bank_statement_id')->constrained('accounting_bank_transaction_mappings')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_account_moves', 'cash_flow_category')) {
                $table->string('cash_flow_category')->nullable()->after('bank_mapping_id');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'tax_treatment')) {
                $table->string('tax_treatment')->nullable()->after('cash_flow_category');
            }
            if (! Schema::hasColumn('accounts_account_moves', 'review_status')) {
                $table->string('review_status')->nullable()->after('tax_treatment');
            }
        });

        if (! Schema::hasIndex('accounts_account_moves', 'account_moves_accounting_source_unique')) {
            Schema::table('accounts_account_moves', function (Blueprint $table) {
                $table->unique(['company_id', 'accounting_source_type', 'accounting_source_id'], 'account_moves_accounting_source_unique');
            });
        }
    }

    private function addCoaBatchColumns(): void
    {
        $columns = [
            'source_sheet'      => fn (Blueprint $table) => $table->string('source_sheet')->nullable()->after('filename'),
            'header_row_number' => fn (Blueprint $table) => $table->unsignedInteger('header_row_number')->nullable()->after('source_sheet'),
            'file_hash'         => fn (Blueprint $table) => $table->char('file_hash', 64)->nullable()->after('header_row_number'),
            'original_headers'  => fn (Blueprint $table) => $table->json('original_headers')->nullable()->after('file_hash'),
            'metadata_rows'     => fn (Blueprint $table) => $table->json('metadata_rows')->nullable()->after('original_headers'),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('accounting_coa_import_batches', $column)) {
                Schema::table('accounting_coa_import_batches', $definition);
            }
        }
    }

    private function createCoaSourceRowsTable(): void
    {
        if (Schema::hasTable('accounting_coa_import_source_rows')) {
            return;
        }

        Schema::create('accounting_coa_import_source_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id');
            $table->foreignId('company_id');
            $table->foreignId('canonical_account_id')->nullable();
            $table->unsignedInteger('row_order');
            $table->unsignedInteger('source_row_number');
            $table->string('nature')->nullable();
            $table->string('classification_1')->nullable();
            $table->string('classification_2')->nullable();
            $table->string('classification_3')->nullable();
            $table->string('classification_4')->nullable();
            $table->string('classification_5')->nullable();
            $table->string('classification_6')->nullable();
            $table->string('classification_7')->nullable();
            $table->string('code')->nullable();
            $table->string('title')->nullable();
            $table->decimal('opening_debit', 20, 4)->default(0);
            $table->decimal('opening_credit', 20, 4)->default(0);
            $table->decimal('movement_debit', 20, 4)->default(0);
            $table->decimal('movement_credit', 20, 4)->default(0);
            $table->decimal('adjustment_debit', 20, 4)->default(0);
            $table->decimal('adjustment_credit', 20, 4)->default(0);
            $table->decimal('closing_debit', 20, 4)->default(0);
            $table->decimal('closing_credit', 20, 4)->default(0);
            $table->json('classification_values')->nullable();
            $table->json('raw_row')->nullable();
            $table->json('raw_row_by_header')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'row_order'], 'coa_source_rows_batch_order_unique');
            $table->index(['company_id', 'code']);
            $table->foreign('batch_id', 'coa_source_rows_batch_fk')->references('id')->on('accounting_coa_import_batches')->cascadeOnDelete();
            $table->foreign('company_id', 'coa_source_rows_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('canonical_account_id', 'coa_source_rows_account_fk')->references('id')->on('accounts_accounts')->nullOnDelete();
        });
    }

    private function addBankStatementColumns(): void
    {
        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_bank_statements', 'currency_id')) {
                $table->foreignId('currency_id')->nullable()->after('journal_id')->constrained('currencies')->nullOnDelete();
            }
            if (! Schema::hasColumn('accounts_bank_statements', 'bank_gl_account_id')) {
                $table->foreignId('bank_gl_account_id')->nullable()->after('currency_id')->constrained('accounts_accounts')->nullOnDelete();
            }

            $definitions = [
                'bank_name'            => fn () => $table->string('bank_name')->nullable()->after('reference'),
                'bank_account_number'  => fn () => $table->string('bank_account_number')->nullable()->after('bank_name'),
                'account_title'        => fn () => $table->string('account_title')->nullable()->after('bank_account_number'),
                'statement_start_date' => fn () => $table->date('statement_start_date')->nullable()->after('date'),
                'statement_end_date'   => fn () => $table->date('statement_end_date')->nullable()->after('statement_start_date'),
                'opening_balance'      => fn () => $table->decimal('opening_balance', 20, 4)->default(0)->after('statement_end_date'),
                'total_debits'         => fn () => $table->decimal('total_debits', 20, 4)->default(0)->after('opening_balance'),
                'total_credits'        => fn () => $table->decimal('total_credits', 20, 4)->default(0)->after('total_debits'),
                'closing_balance'      => fn () => $table->decimal('closing_balance', 20, 4)->default(0)->after('total_credits'),
                'original_filename'    => fn () => $table->string('original_filename')->nullable()->after('closing_balance'),
                'file_hash'            => fn () => $table->char('file_hash', 64)->nullable()->after('original_filename'),
                'source_sheet'         => fn () => $table->string('source_sheet')->nullable()->after('file_hash'),
                'parser'               => fn () => $table->string('parser')->nullable()->after('source_sheet'),
                'import_status'        => fn () => $table->string('import_status')->default('awaiting_review')->after('parser'),
                'validation_errors'    => fn () => $table->json('validation_errors')->nullable()->after('import_status'),
                'raw_header'           => fn () => $table->json('raw_header')->nullable()->after('validation_errors'),
            ];

            foreach ($definitions as $column => $definition) {
                if (! Schema::hasColumn('accounts_bank_statements', $column)) {
                    $definition();
                }
            }
        });

        if (! Schema::hasIndex('accounts_bank_statements', 'bank_statements_company_file_hash_unique')) {
            Schema::table('accounts_bank_statements', fn (Blueprint $table) => $table->unique(['company_id', 'file_hash'], 'bank_statements_company_file_hash_unique'));
        }
        if (! Schema::hasIndex('accounts_bank_statements', 'bank_statements_account_period_index')) {
            Schema::table('accounts_bank_statements', fn (Blueprint $table) => $table->index(['company_id', 'bank_account_number', 'statement_start_date'], 'bank_statements_account_period_index'));
        }
    }

    private function addBankStatementLineColumns(): void
    {
        Schema::table('accounts_bank_statement_lines', function (Blueprint $table) {
            $definitions = [
                'transaction_date'       => fn () => $table->date('transaction_date')->nullable()->after('internal_index'),
                'value_date'             => fn () => $table->date('value_date')->nullable()->after('transaction_date'),
                'description'            => fn () => $table->text('description')->nullable()->after('value_date'),
                'reference'              => fn () => $table->string('reference')->nullable()->after('description'),
                'debit'                  => fn () => $table->decimal('debit', 20, 4)->default(0)->after('reference'),
                'credit'                 => fn () => $table->decimal('credit', 20, 4)->default(0)->after('debit'),
                'running_balance'        => fn () => $table->decimal('running_balance', 20, 4)->nullable()->after('credit'),
                'source_row'             => fn () => $table->unsignedInteger('source_row')->nullable()->after('running_balance'),
                'raw_row'                => fn () => $table->json('raw_row')->nullable()->after('source_row'),
                'transaction_fingerprint'=> fn () => $table->char('transaction_fingerprint', 64)->nullable()->after('raw_row'),
                'import_status'          => fn () => $table->string('import_status')->default('imported')->after('transaction_fingerprint'),
            ];

            foreach ($definitions as $column => $definition) {
                if (! Schema::hasColumn('accounts_bank_statement_lines', $column)) {
                    $definition();
                }
            }
        });

        if (! Schema::hasIndex('accounts_bank_statement_lines', 'bank_statement_lines_fingerprint_unique')) {
            Schema::table('accounts_bank_statement_lines', fn (Blueprint $table) => $table->unique(['statement_id', 'transaction_fingerprint'], 'bank_statement_lines_fingerprint_unique'));
        }
        if (! Schema::hasIndex('accounts_bank_statement_lines', ['company_id', 'transaction_date'])) {
            Schema::table('accounts_bank_statement_lines', fn (Blueprint $table) => $table->index(['company_id', 'transaction_date']));
        }
    }

    public function down(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->dropForeign(['bank_statement_id']);
            $table->dropForeign(['bank_mapping_id']);
            $table->dropUnique('account_moves_accounting_source_unique');
            $table->dropColumn([
                'accounting_source_type',
                'accounting_source_id',
                'bank_statement_id',
                'bank_mapping_id',
                'cash_flow_category',
                'tax_treatment',
                'review_status',
            ]);
        });

        Schema::dropIfExists('accounting_manual_adjustments');
        Schema::dropIfExists('accounting_bank_transaction_mappings');
        Schema::dropIfExists('accounting_bank_mapping_rules');
        Schema::dropIfExists('accounting_bank_transfer_matches');

        Schema::table('accounts_bank_statement_lines', function (Blueprint $table) {
            $table->dropUnique('bank_statement_lines_fingerprint_unique');
            $table->dropIndex(['company_id', 'transaction_date']);
            $table->dropColumn([
                'transaction_date',
                'value_date',
                'description',
                'reference',
                'debit',
                'credit',
                'running_balance',
                'source_row',
                'raw_row',
                'transaction_fingerprint',
                'import_status',
            ]);
        });

        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['bank_gl_account_id']);
            $table->dropUnique('bank_statements_company_file_hash_unique');
            $table->dropIndex('bank_statements_account_period_index');
            $table->dropColumn([
                'currency_id',
                'bank_gl_account_id',
                'bank_name',
                'bank_account_number',
                'account_title',
                'statement_start_date',
                'statement_end_date',
                'opening_balance',
                'total_debits',
                'total_credits',
                'closing_balance',
                'original_filename',
                'file_hash',
                'source_sheet',
                'parser',
                'import_status',
                'validation_errors',
                'raw_header',
            ]);
        });

        Schema::dropIfExists('accounting_coa_import_source_rows');

        Schema::table('accounting_coa_import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'source_sheet',
                'header_row_number',
                'file_hash',
                'original_headers',
                'metadata_rows',
            ]);
        });
    }
};
