<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;

class BankStatementValidationService
{
    public function __construct(protected string $tolerance = '0.01') {}

    /**
     * @return array<int, array{code: string, message: string, source_row: ?int}>
     */
    public function validate(NormalizedBankStatement $statement): array
    {
        $errors = [];
        $calculatedClosing = BigDecimal::of($statement->openingBalance)
            ->plus($statement->totalCredits)
            ->minus($statement->totalDebits)
            ->toScale(2, RoundingMode::HalfUp);

        if ($calculatedClosing->minus($statement->closingBalance)->abs()->isGreaterThan($this->tolerance)) {
            $errors[] = $this->error(
                'header_reconciliation',
                "Opening + credits - debits is {$calculatedClosing}, not {$statement->closingBalance}.",
            );
        }

        $rowDebits = collect($statement->transactions)->reduce(
            fn (BigDecimal $total, $row): BigDecimal => $total->plus($row->debit),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HalfUp);
        $rowCredits = collect($statement->transactions)->reduce(
            fn (BigDecimal $total, $row): BigDecimal => $total->plus($row->credit),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HalfUp);

        if ($rowDebits->minus($statement->totalDebits)->abs()->isGreaterThan($this->tolerance)) {
            $errors[] = $this->error('debit_total', "Transaction debits {$rowDebits} do not equal header debits {$statement->totalDebits}.");
        }

        if ($rowCredits->minus($statement->totalCredits)->abs()->isGreaterThan($this->tolerance)) {
            $errors[] = $this->error('credit_total', "Transaction credits {$rowCredits} do not equal header credits {$statement->totalCredits}.");
        }

        $running = BigDecimal::of($statement->openingBalance);
        $fingerprints = [];

        foreach ($statement->transactions as $transaction) {
            if ($transaction->transactionDate === '') {
                $errors[] = $this->error('missing_date', 'Transaction date is missing.', $transaction->sourceRow);
            }

            if (BigDecimal::of($transaction->debit)->isPositive() && BigDecimal::of($transaction->credit)->isPositive()) {
                $errors[] = $this->error('invalid_direction', 'A row cannot contain both a debit and a credit.', $transaction->sourceRow);
            }

            if (! BigDecimal::of($transaction->debit)->isPositive() && ! BigDecimal::of($transaction->credit)->isPositive()) {
                $errors[] = $this->error('zero_amount', 'A row must contain either a debit or a credit.', $transaction->sourceRow);
            }

            $running = $running->minus($transaction->debit)->plus($transaction->credit)->toScale(2, RoundingMode::HalfUp);
            if ($transaction->runningBalance !== null && $running->minus($transaction->runningBalance)->abs()->isGreaterThan($this->tolerance)) {
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
