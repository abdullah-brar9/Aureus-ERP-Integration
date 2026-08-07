<?php

namespace Webkul\Accounting\Services\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;
use Webkul\Accounting\Data\Bank\NormalizedBankTransaction;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Models\ImportSourceRow;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Accounting\Services\PartyClassificationService;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

final class ImportExecutionService
{
    public function confirm(ImportRun $run, ?int $userId = null): ImportRun
    {
        return DB::transaction(function () use ($run, $userId): ImportRun {
            $lockedRun = ImportRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedRun->loadMissing('profile');

            if ($lockedRun->status !== 'previewed') {
                throw new RuntimeException('Only a previewed import run can be confirmed.');
            }
            if ($lockedRun->failed_rows > 0) {
                throw new RuntimeException('Correct all preview errors before confirming the import.');
            }
            if ($lockedRun->profile->company_id !== $lockedRun->company_id) {
                throw new RuntimeException('The profile and import run company do not match.');
            }

            $lockedRun->update([
                'status'         => 'processing',
                'imported_by_id' => $userId ?? $lockedRun->imported_by_id,
                'confirmed_at'   => now(),
            ]);

            $imported = 0;
            if ($lockedRun->profile->entity_type === 'bank_statement') {
                $statement = $this->createBankStatement($lockedRun);
                $linesBySourceRow = $statement->lines->keyBy('source_row');
                foreach ($lockedRun->sourceRows as $sourceRow) {
                    $line = $linesBySourceRow->get($sourceRow->source_row_number);
                    if (! $line) {
                        throw new RuntimeException("Imported bank line for source row {$sourceRow->source_row_number} was not created.");
                    }
                    $sourceRow->update([
                        'canonical_type' => $line->getMorphClass(),
                        'canonical_id'   => $line->id,
                        'processed_at'   => now(),
                    ]);
                    $tagCode = trim((string) (
                        ($sourceRow->transformed_values ?? [])['FS Tag']
                        ?? ($sourceRow->transformed_values ?? [])['fs_tag']
                        ?? ''
                    ));

                    $tag = null;

                    if ($tagCode !== '') {
                        $tag = FsTag::query()
                            ->where('company_id', $lockedRun->company_id)
                            ->where('code', $tagCode)
                            ->where('is_active', true)
                            ->first();
                    }

                    if ($line->mapping) {
                        $line->mapping->update([
                            'fs_tag_id' => $tag?->id,
                            'match_type' => $tag ? 'fs_tag' : null,
                            'review_status' => $tagCode !== '' && ! $tag
                                ? 'needs_review'
                                : $line->mapping->review_status,
                        ]);
                    }
                    $imported++;
                }
            } else {
                ImportSourceRow::query()
                    ->where('run_id', $lockedRun->id)
                    ->whereIn('status', ['pass', 'warning'])
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($lockedRun, &$imported): void {
                        foreach ($rows as $row) {
                            $record = $this->createCanonicalRecord($lockedRun, (array) $row->transformed_values);
                            $row->update([
                                'canonical_type' => $record->getMorphClass(),
                                'canonical_id'   => $record->getKey(),
                                'processed_at'   => now(),
                            ]);
                            $imported++;
                        }
                    });
            }

            $lockedRun->update([
                'status'        => 'completed',
                'imported_rows' => $imported,
                'completed_at'  => now(),
            ]);

            return $lockedRun->fresh(['sourceRows']);
        });
    }

    /** @param array<string, mixed> $values */
    private function createCanonicalRecord(ImportRun $run, array $values): Model
    {
        return match ($run->profile->entity_type) {
            'vendor', 'customer'                        => $this->createParty($run, $values),
            'employee'                                  => $this->createEmployee($run, $values),
            'invoice', 'bill', 'claim', 'miscellaneous' => $this->createDocument($run, $values),
            'bank_statement'                            => throw new RuntimeException('Bank statement rows are imported as one controlled statement batch.'),
            default                                     => throw new RuntimeException("Unsupported import entity [{$run->profile->entity_type}]."),
        };
    }

    private function createBankStatement(ImportRun $run): BankStatement
    {
        $rows = $run->sourceRows()->whereIn('status', ['pass', 'warning'])->orderBy('source_row_number')->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException('The bank statement import has no passing rows.');
        }

        $values = $rows->map(fn(ImportSourceRow $row): array => (array) $row->transformed_values);
        foreach (['currency', 'bank_account_number', 'journal_code', 'bank_gl_code'] as $field) {
            if ($values->pluck($field)->filter(fn($value): bool => $value !== null && $value !== '')->map(fn($value): string => mb_strtoupper(trim((string) $value)))->unique()->count() !== 1) {
                throw new RuntimeException("All bank statement rows must use one {$field}.");
            }
        }

        $first = $values->first();
        $company = Company::query()->findOrFail($run->company_id);
        $currency = Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $first['currency'])])->firstOrFail();
        $journal = Journal::query()->where('company_id', $run->company_id)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $first['journal_code'])])->firstOrFail();
        $bankAccount = Account::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $first['bank_gl_code'])])
            ->whereHas('companies', fn($query) => $query->where('companies.id', $run->company_id))->firstOrFail();

        $totalDebits = BigDecimal::zero();
        $totalCredits = BigDecimal::zero();
        $dates = [];
        $transactions = [];
        foreach ($rows as $row) {
            $value = (array) $row->transformed_values;
            $debit = BigDecimal::of((string) ($value['debit'] ?? '0'));
            $credit = BigDecimal::of((string) ($value['credit'] ?? '0'));
            $totalDebits = $totalDebits->plus($debit);
            $totalCredits = $totalCredits->plus($credit);
            $dates[] = (string) $value['date'];
            $transactions[] = new NormalizedBankTransaction(
                transactionDate: (string) $value['date'],
                valueDate: filled($value['value_date'] ?? null) ? (string) $value['value_date'] : null,
                description: (string) $value['description'],
                reference: filled($value['reference'] ?? null) ? (string) $value['reference'] : null,
                debit: $debit->__toString(),
                credit: $credit->__toString(),
                runningBalance: filled($value['balance'] ?? null) ? (string) $value['balance'] : null,
                sourceRow: $row->source_row_number,
                rawRow: (array) $row->raw_values,
            );
        }

        sort($dates);
        $opening = BigDecimal::of((string) ($first['opening_balance'] ?? '0'));
        $last = $values->last();
        $closing = filled($last['closing_balance'] ?? null)
            ? BigDecimal::of((string) $last['closing_balance'])
            : (filled($last['balance'] ?? null)
                ? BigDecimal::of((string) $last['balance'])
                : $opening->plus($totalCredits)->minus($totalDebits));
        $parserKey = "profile-run-{$run->id}";
        $normalized = new NormalizedBankStatement(
            bank: (string) ($first['bank_name'] ?? 'Imported bank'),
            bankAccountNumber: (string) $first['bank_account_number'],
            accountTitle: (string) ($first['account_title'] ?? ''),
            currency: (string) $first['currency'],
            statementStartDate: $dates[0],
            statementEndDate: $dates[array_key_last($dates)],
            openingBalance: $opening->__toString(),
            totalDebits: $totalDebits->__toString(),
            totalCredits: $totalCredits->__toString(),
            closingBalance: $closing->__toString(),
            parser: $parserKey,
            sourceSheet: $run->source_sheet,
            rawHeader: (array) ($run->summary['headers'] ?? []),
            transactions: $transactions,
        );
        $path = (string) ($run->summary['staged_path'] ?? '');
        if (! is_file($path)) {
            throw new RuntimeException('The staged source file is no longer available; preview the file again.');
        }

        app(BankStatementParserRegistry::class)->register(new ProfileBankStatementParser($parserKey, $normalized));

        return app(BankStatementImportService::class)->import(
            $path,
            $company,
            $journal,
            $bankAccount,
            $currency,
            $parserKey,
            $run->source_sheet,
            $run->original_filename,
            false,
        );
    }

    /** @param array<string, mixed> $values */
    private function createParty(ImportRun $run, array $values): Partner
    {
        $reference = trim((string) ($values['reference'] ?? ''));
        $identity = $reference !== ''
            ? ['company_id' => $run->company_id, 'reference' => $reference]
            : ['company_id' => $run->company_id, 'name' => trim((string) $values['name']), 'email' => $values['email'] ?? null];

        $party = Partner::query()->firstOrCreate($identity, [
            'account_type' => 'company',
            'sub_type'     => $run->profile->entity_type,
            'name'         => trim((string) $values['name']),
            'email'        => $values['email'] ?? null,
            'phone'        => $values['phone'] ?? null,
            'mobile'       => $values['mobile'] ?? null,
            'tax_id'       => $values['tax_id'] ?? null,
            'is_active'    => $values['is_active'] ?? true,
            'supplier_rank' => $run->profile->entity_type === 'vendor' ? 1 : 0,
            'customer_rank' => $run->profile->entity_type === 'customer' ? 1 : 0,
        ]);

        if (! empty($values['payment_term'])) {
            $term = PaymentTerm::query()->where('company_id', $run->company_id)->where('name', $values['payment_term'])->first();
            $field = $run->profile->entity_type === 'vendor' ? 'property_supplier_payment_term_id' : 'property_payment_term_id';
            if ($term && ! $party->getAttribute($field)) {
                $party->update([$field => $term->id]);
            }
        }
        $company = Company::query()->findOrFail($run->company_id);
        foreach (['classification', 'sector', 'category'] as $classificationType) {
            app(PartyClassificationService::class)->assign($company, $party, $classificationType, $values[$classificationType] ?? null);
        }

        return $party;
    }

    /** @param array<string, mixed> $values */
    private function createEmployee(ImportRun $run, array $values): Employee
    {
        $employeeId = trim((string) ($values['identification_id'] ?? ''));
        $identity = $employeeId !== ''
            ? ['company_id' => $run->company_id, 'identification_id' => $employeeId]
            : ['company_id' => $run->company_id, 'work_email' => $values['work_email'] ?? null, 'name' => trim((string) $values['name'])];

        $department = empty($values['department']) ? null : Department::query()->firstOrCreate([
            'company_id' => $run->company_id,
            'name'       => trim((string) $values['department']),
        ]);

        return Employee::query()->firstOrCreate($identity, [
            'name'         => trim((string) $values['name']),
            'work_email'   => $values['work_email'] ?? null,
            'work_phone'   => $values['work_phone'] ?? null,
            'mobile_phone' => $values['mobile_phone'] ?? null,
            'job_title'    => $values['job_title'] ?? null,
            'department_id' => $department?->id,
            'is_active'    => $values['is_active'] ?? true,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function createDocument(ImportRun $run, array $values): Move
    {
        $profileType = $run->profile->entity_type;
        $moveType = match ($profileType) {
            'invoice'       => MoveType::OUT_INVOICE,
            'bill'          => MoveType::IN_INVOICE,
            'claim'         => MoveType::IN_RECEIPT,
            'miscellaneous' => MoveType::ENTRY,
        };

        $currency = Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['currency'])])->firstOrFail();
        $journal = Journal::query()
            ->where('company_id', $run->company_id)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
            ->firstOrFail();
        $partner = empty($values['partner_reference'])
            ? null
            : Partner::query()->where('company_id', $run->company_id)->where('reference', $values['partner_reference'])->firstOrFail();

        $move = Move::query()->firstOrCreate([
            'company_id' => $run->company_id,
            'reference'  => trim((string) $values['reference']),
            'move_type'  => $moveType,
        ], [
            'journal_id'             => $journal->id,
            'partner_id'             => $partner?->id,
            'currency_id'            => $currency->id,
            'original_currency_id'   => $currency->id,
            'date'                   => $values['date'],
            'invoice_date'           => $values['date'],
            'invoice_date_due'       => $values['due_date'] ?? $values['date'],
            'narration'              => $values['description'] ?? null,
            'amount_untaxed'         => $values['amount_untaxed'] ?? $values['amount_total'],
            'amount_tax'             => $values['amount_tax'] ?? '0.0000',
            'amount_total'           => $values['amount_total'],
            'amount_residual'        => $values['amount_total'],
            'state'                  => MoveState::DRAFT,
            'payment_state'          => PaymentState::NOT_PAID,
            'accounting_source_type' => ImportRun::class,
            'accounting_source_id'   => $run->id,
            'review_status'          => 'draft',
            'cash_flow_category'     => $values['cash_flow_category'] ?? null,
            'tax_treatment'          => $values['tax_treatment'] ?? null,
        ]);

        if (! $move->wasRecentlyCreated) {
            return $move;
        }

        $debitAccount = Account::query()->postable()->where('deprecated', false)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['debit_gl_code'])])
            ->whereHas('companies', fn($query) => $query->where('companies.id', $run->company_id))->firstOrFail();
        $creditAccount = Account::query()->postable()->where('deprecated', false)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['credit_gl_code'])])
            ->whereHas('companies', fn($query) => $query->where('companies.id', $run->company_id))->firstOrFail();
        if ($debitAccount->is($creditAccount)) {
            throw new RuntimeException('Document debit and credit GL accounts must be different.');
        }

        $company = Company::query()->with('currency')->findOrFail($run->company_id);
        $rate = app(ExchangeRateService::class)->resolve($company, $currency, $company->currency, (string) $values['date']);
        $originalAmount = BigDecimal::of((string) $values['amount_total'])->toScale(4, RoundingMode::HalfUp)->__toString();
        if (! BigDecimal::of($originalAmount)->isPositive()) {
            throw new RuntimeException('Document amount must be greater than zero.');
        }
        $companyAmount = app(ExchangeRateService::class)->convert($originalAmount, $rate);
        $common = [
            'move_id'             => $move->id,
            'journal_id' => $journal->id,
            'company_id' => $run->company_id,
            'company_currency_id' => $company->currency_id,
            'currency_id' => $currency->id,
            'original_currency_id' => $currency->id,
            'partner_id'          => $partner?->id,
            'date' => $values['date'],
            'invoice_date' => $values['date'],
            'date_maturity'       => $values['due_date'] ?? $values['date'],
            'parent_state' => MoveState::DRAFT,
            'reference'           => $values['reference'],
            'name' => $values['description'] ?? $values['reference'],
            'display_type'        => DisplayType::PRODUCT,
            'exchange_rate_id'    => $rate->recordId,
            'exchange_rate' => $rate->rate,
            'rate_date' => $rate->effectiveDate,
            'rate_source'         => $rate->source,
            'rate_type' => $rate->type,
            'conversion_status' => 'complete',
            'is_imported' => true,
        ];
        MoveLine::query()->create($common + [
            'sort'            => 0,
            'account_id' => $debitAccount->id,
            'debit'           => $companyAmount,
            'credit' => '0.0000',
            'balance' => $companyAmount,
            'original_debit'  => $originalAmount,
            'original_credit' => '0.0000',
            'original_signed_amount' => $originalAmount,
            'company_debit'   => $companyAmount,
            'company_credit' => '0.0000',
            'company_signed_amount' => $companyAmount,
            'amount_currency' => $originalAmount,
        ]);
        MoveLine::query()->create($common + [
            'sort'            => 1,
            'account_id' => $creditAccount->id,
            'debit'           => '0.0000',
            'credit' => $companyAmount,
            'balance' => BigDecimal::of($companyAmount)->negated()->__toString(),
            'original_debit'  => '0.0000',
            'original_credit' => $originalAmount,
            'original_signed_amount' => BigDecimal::of($originalAmount)->negated()->__toString(),
            'company_debit'   => '0.0000',
            'company_credit' => $companyAmount,
            'company_signed_amount' => BigDecimal::of($companyAmount)->negated()->__toString(),
            'amount_currency' => BigDecimal::of($originalAmount)->negated()->__toString(),
        ]);

        return $move->fresh('lines');
    }
}
