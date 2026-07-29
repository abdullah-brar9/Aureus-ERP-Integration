<?php

namespace Webkul\Accounting\Data\Bank;

final class NormalizedBankTransaction
{
    public function __construct(
        public readonly string $transactionDate,
        public readonly ?string $valueDate,
        public readonly string $description,
        public readonly ?string $reference,
        public readonly float $debit,
        public readonly float $credit,
        public readonly ?float $runningBalance,
        public readonly int $sourceRow,
        public readonly array $rawRow,
    ) {}

    public function fingerprint(string $bankAccountNumber): string
    {
        return hash('sha256', implode('|', [
            mb_strtoupper(trim($bankAccountNumber)),
            $this->transactionDate,
            $this->valueDate,
            preg_replace('/\s+/', ' ', mb_strtolower(trim($this->description))),
            mb_strtoupper(trim((string) $this->reference)),
            number_format($this->debit, 4, '.', ''),
            number_format($this->credit, 4, '.', ''),
            number_format((float) $this->runningBalance, 4, '.', ''),
        ]));
    }
}
