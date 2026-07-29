<?php

namespace Webkul\Accounting\Data;

use Webkul\Accounting\Enums\ValueBasis;

/**
 * The scope and read-instruction under which a batch of measures is resolved.
 *
 * Reuses the existing ReportContext (company scope + posted flag) rather than
 * duplicating it, and adds the ValueBasis (movement / opening / closing) that
 * the ledger read needs. Non-ledger resolvers may ignore the basis.
 */
final class ResolutionContext
{
    public function __construct(
        public readonly ReportContext $reportContext,
        public readonly ValueBasis $basis = ValueBasis::MOVEMENT,
    ) {}

    public static function for(ReportContext $reportContext, ValueBasis $basis): self
    {
        return new self($reportContext, $basis);
    }
}
