<?php

namespace Webkul\Accounting\Services\Bank;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankStatementImportService
{
    public function __construct(
        protected BankStatementParserRegistry $parsers,
        protected BankStatementValidationService $validator,
    ) {}

    public function import(
        string $path,
        Company $company,
        Journal $journal,
        Account $bankGlAccount,
        Currency $currency,
        ?string $parserKey = null,
        ?string $sheetName = null,
        ?string $originalFilename = null,
    ): BankStatement {
        $this->assertTarget($company, $journal, $bankGlAccount, $currency);

        $fileHash = hash_file('sha256', $path);
        if ($fileHash === false) {
            throw new RuntimeException('Unable to fingerprint the bank statement file.');
        }

        $parser = $this->parsers->resolve($path, $parserKey, $sheetName);
        $normalized = $parser->parse($path, $sheetName);

        if (BankStatement::query()
            ->where('company_id', $company->id)
            ->where('file_hash', $fileHash)
            ->where('parser', $normalized->parser)
            ->exists()) {
            throw new RuntimeException('This bank statement source has already been imported for the company.');
        }

        $errors = $this->validator->validate($normalized);

        if (mb_strtoupper($normalized->currency) !== mb_strtoupper($currency->name)) {
            $errors[] = [
                'code'       => 'currency_mismatch',
                'message'    => "Statement currency {$normalized->currency} does not match {$currency->name}.",
                'source_row' => null,
            ];
        }

        $overlap = BankStatement::query()
            ->where('company_id', $company->id)
            ->where('bank_account_number', $normalized->bankAccountNumber)
            ->whereDate('statement_start_date', '<=', $normalized->statementEndDate)
            ->whereDate('statement_end_date', '>=', $normalized->statementStartDate)
            ->exists();

        if ($overlap) {
            $errors[] = [
                'code'       => 'overlapping_period',
                'message'    => 'Another statement for this bank account overlaps the imported period.',
                'source_row' => null,
            ];
        }

        return DB::transaction(function () use (
            $normalized,
            $errors,
            $company,
            $journal,
            $bankGlAccount,
            $currency,
            $fileHash,
            $originalFilename,
            $path,
        ): BankStatement {
            $status = $errors === []
                ? BankImportStatus::Validated
                : BankImportStatus::ReconciliationFailed;

            $statement = BankStatement::query()->create([
                'company_id'           => $company->id,
                'journal_id'           => $journal->id,
                'currency_id'          => $currency->id,
                'bank_gl_account_id'   => $bankGlAccount->id,
                'name'                 => "{$normalized->bank} {$normalized->statementStartDate} to {$normalized->statementEndDate}",
                'reference'            => $normalized->bankAccountNumber,
                'bank_name'            => $normalized->bank,
                'bank_account_number'  => $normalized->bankAccountNumber,
                'account_title'        => $normalized->accountTitle,
                'date'                 => $normalized->statementEndDate,
                'statement_start_date' => $normalized->statementStartDate,
                'statement_end_date'   => $normalized->statementEndDate,
                'opening_balance'      => $normalized->openingBalance,
                'total_debits'         => $normalized->totalDebits,
                'total_credits'        => $normalized->totalCredits,
                'closing_balance'      => $normalized->closingBalance,
                'balance_start'        => $normalized->openingBalance,
                'balance_end'          => $normalized->closingBalance,
                'balance_end_real'     => $normalized->closingBalance,
                'original_filename'    => $originalFilename ?? basename($path),
                'file_hash'            => $fileHash,
                'source_sheet'         => $normalized->sourceSheet,
                'parser'               => $normalized->parser,
                'import_status'        => $status->value,
                'validation_errors'    => $errors,
                'raw_header'           => $normalized->rawHeader,
                'is_completed'         => false,
            ]);

            foreach ($normalized->transactions as $sort => $transaction) {
                $fingerprint = $transaction->fingerprint($normalized->bankAccountNumber);
                $line = BankStatementLine::query()->create([
                    'sort'                    => $sort,
                    'journal_id'              => $journal->id,
                    'company_id'              => $company->id,
                    'statement_id'            => $statement->id,
                    'currency_id'             => $currency->id,
                    'account_number'          => $normalized->bankAccountNumber,
                    'transaction_type'        => $transaction->credit > 0 ? 'credit' : 'debit',
                    'payment_reference'       => $transaction->reference,
                    'internal_index'          => $fingerprint,
                    'transaction_date'        => $transaction->transactionDate ?: null,
                    'value_date'              => $transaction->valueDate,
                    'description'             => $transaction->description,
                    'reference'               => $transaction->reference,
                    'debit'                   => $transaction->debit,
                    'credit'                  => $transaction->credit,
                    'running_balance'         => $transaction->runningBalance,
                    'source_row'              => $transaction->sourceRow,
                    'raw_row'                 => $transaction->rawRow,
                    'transaction_fingerprint' => $fingerprint,
                    'import_status'           => $status->value,
                    'transaction_details'     => $transaction->rawRow,
                    'amount'                  => $transaction->credit - $transaction->debit,
                    'amount_currency'         => $transaction->credit - $transaction->debit,
                    'amount_residual'         => $transaction->credit - $transaction->debit,
                    'is_reconciled'           => false,
                ]);

                BankTransactionMapping::query()->create([
                    'company_id'         => $company->id,
                    'statement_line_id'  => $line->id,
                    'bank_gl_account_id' => $bankGlAccount->id,
                    'review_status'      => BankReviewStatus::Unmapped,
                    'posting_status'     => BankPostingStatus::NotPosted,
                ]);
            }

            return $statement->fresh(['lines.mapping']);
        });
    }

    protected function assertTarget(Company $company, Journal $journal, Account $bankGlAccount, Currency $currency): void
    {
        if ($journal->company_id !== $company->id) {
            throw new RuntimeException('The selected journal belongs to another company.');
        }

        if ($journal->type !== JournalType::BANK) {
            throw new RuntimeException('Bank statements can only be imported into a bank journal.');
        }

        if ($journal->currency_id && (int) $journal->currency_id !== (int) $currency->id) {
            throw new RuntimeException('The bank journal currency does not match the selected statement currency.');
        }

        if ($bankGlAccount->deprecated || $bankGlAccount->is_group) {
            throw new RuntimeException('The bank GL must be an active postable account.');
        }

        if (! $bankGlAccount->companies()->where('companies.id', $company->id)->exists()) {
            throw new RuntimeException('The bank GL account belongs to another company.');
        }

        if ($bankGlAccount->currency_id && (int) $bankGlAccount->currency_id !== (int) $currency->id) {
            throw new RuntimeException('The bank GL currency does not match the selected statement currency.');
        }
    }
}
