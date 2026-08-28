<?php

namespace Webkul\Accounting\Data\Coa;

/**
 * Zero-based column indices for a Chart-of-Accounts sheet, resolved by the
 * header detector. Classification columns are variable in count and order; the
 * eight balance columns follow the Title column in the fixed accounting order
 * Opening/Movement/Adjustment/Closing × Debit/Credit.
 */
final class CoaColumnMap
{
    /**
     * @param  array<int, int>  $classificationCols  ordered indices of the Classification columns
     */
    public function __construct(
        public readonly int $headerRowIndex,
        public readonly int $natureCol,
        public readonly array $classificationCols,
        public readonly int $codeCol,
        public readonly int $titleCol,
        public readonly ?int $openingDebitCol,
        public readonly ?int $openingCreditCol,
        public readonly ?int $movementDebitCol,
        public readonly ?int $movementCreditCol,
        public readonly ?int $adjustmentDebitCol,
        public readonly ?int $adjustmentCreditCol,
        public readonly ?int $closingDebitCol,
        public readonly ?int $closingCreditCol,
    ) {}

    public function firstDataRowIndex(): int
    {
        return $this->headerRowIndex + 1;
    }
}
