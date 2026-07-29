<?php

namespace Webkul\Accounting\Data;

/**
 * Identifies one measurable quantity to resolve, independent of where it comes
 * from. `source` selects the resolver (e.g. "ledger"); `key` identifies the
 * thing being measured within that source (for the ledger, an account id).
 *
 * This is the address a report line hands to the MeasureResolver layer. Phase 0
 * only produces ledger references; imported-dataset / manual / external
 * references reuse the same shape without changing the contract.
 */
final class MeasureReference
{
    public const SOURCE_LEDGER = 'ledger';

    public function __construct(
        public readonly string $source,
        public readonly int|string $key,
        public readonly DimensionFilter $filter = new DimensionFilter,
    ) {}

    /**
     * A single ledger account's balance.
     */
    public static function ledgerAccount(int $accountId): self
    {
        return new self(self::SOURCE_LEDGER, $accountId);
    }
}
