<?php

namespace Webkul\Accounting\Data\Bank;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class NormalizedBankTransaction
{
    public function __construct(
        public readonly string $transactionDate,
        public readonly ?string $valueDate,
        public readonly string $description,
        public readonly ?string $reference,
        public readonly string $debit,
        public readonly string $credit,
        public readonly ?string $runningBalance,
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
            BigDecimal::of($this->debit)->toScale(4, RoundingMode::HalfUp),
            BigDecimal::of($this->credit)->toScale(4, RoundingMode::HalfUp),
            BigDecimal::of($this->runningBalance ?? '0')->toScale(4, RoundingMode::HalfUp),
        ]));
    }
}
