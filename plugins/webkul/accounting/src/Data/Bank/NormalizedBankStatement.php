<?php

namespace Webkul\Accounting\Data\Bank;

final class NormalizedBankStatement
{
    /**
     * @param  array<int, NormalizedBankTransaction>  $transactions
     */
    public function __construct(
        public readonly string $bank,
        public readonly string $bankAccountNumber,
        public readonly string $accountTitle,
        public readonly string $currency,
        public readonly string $statementStartDate,
        public readonly string $statementEndDate,
        public readonly string $openingBalance,
        public readonly string $totalDebits,
        public readonly string $totalCredits,
        public readonly string $closingBalance,
        public readonly string $parser,
        public readonly ?string $sourceSheet,
        public readonly array $rawHeader,
        public readonly array $transactions,
    ) {}
}
