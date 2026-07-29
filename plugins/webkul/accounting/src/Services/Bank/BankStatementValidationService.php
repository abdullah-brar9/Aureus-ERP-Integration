<?php

namespace Webkul\Accounting\Services\Bank;

use Webkul\Accounting\Data\Bank\NormalizedBankStatement;

class BankStatementValidationService
{
    public function __construct(protected float $tolerance = 0.01) {}

    /**
     * @return array<int, array{code: string, message: string, source_row: ?int}>
     */
    public function validate(NormalizedBankStatement $statement): array
    {
        $errors = [];
        $calculatedClosing = round($statement->openingBalance + $statement->totalCredits - $statement->totalDebits, 2);

        if (abs($calculatedClosing - $statement->closingBalance) > $this->tolerance) {
            $errors[] = $this->error(
                'header_reconciliation',
                "Opening + credits - debits is {$calculatedClosing}, not {$statement->closingBalance}.",
            );
        }

        $rowDebits = round(array_sum(array_map(fn ($row) => $row->debit, $statement->transactions)), 2);
        $rowCredits = round(array_sum(array_map(fn ($row) => $row->credit, $statement->transactions)), 2);

        if (abs($rowDebits - $statement->totalDebits) > $this->tolerance) {
            $errors[] = $this->error('debit_total', "Transaction debits {$rowDebits} do not equal header debits {$statement->totalDebits}.");
        }

        if (abs($rowCredits - $statement->totalCredits) > $this->tolerance) {
            $errors[] = $this->error('credit_total', "Transaction credits {$rowCredits} do not equal header credits {$statement->totalCredits}.");
        }

        $running = $statement->openingBalance;
        $fingerprints = [];

        foreach ($statement->transactions as $transaction) {
            if ($transaction->transactionDate === '') {
                $errors[] = $this->error('missing_date', 'Transaction date is missing.', $transaction->sourceRow);
            }

            if ($transaction->debit > 0 && $transaction->credit > 0) {
                $errors[] = $this->error('invalid_direction', 'A row cannot contain both a debit and a credit.', $transaction->sourceRow);
            }

            if ($transaction->debit <= 0 && $transaction->credit <= 0) {
                $errors[] = $this->error('zero_amount', 'A row must contain either a debit or a credit.', $transaction->sourceRow);
            }

            $running = round($running - $transaction->debit + $transaction->credit, 2);
            if ($transaction->runningBalance !== null && abs($running - $transaction->runningBalance) > $this->tolerance) {
                $errors[] = $this->error(
                    'running_balance',
                    "Expected running balance {$running}, found {$transaction->runningBalance}.",
                    $transaction->sourceRow,
                );
            }

            $fingerprint = $transaction->fingerprint($statement->bankAccountNumber);
            if (isset($fingerprints[$fingerprint])) {
                $errors[] = $this->error(
                    'duplicate_row',
                    "Duplicate of source row {$fingerprints[$fingerprint]}.",
                    $transaction->sourceRow,
                );
            }
            $fingerprints[$fingerprint] = $transaction->sourceRow;
        }

        return $errors;
    }

    /**
     * @return array{code: string, message: string, source_row: ?int}
     */
    protected function error(string $code, string $message, ?int $sourceRow = null): array
    {
        return ['code' => $code, 'message' => $message, 'source_row' => $sourceRow];
    }
}
