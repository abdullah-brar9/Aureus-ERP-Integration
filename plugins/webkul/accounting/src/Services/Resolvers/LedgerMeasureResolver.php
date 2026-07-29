<?php

namespace Webkul\Accounting\Services\Resolvers;

use Webkul\Accounting\Contracts\MeasureResolver;
use Webkul\Accounting\Data\MeasureReference;
use Webkul\Accounting\Data\ResolutionContext;
use Webkul\Accounting\Data\ResolvedSeries;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;

/**
 * The ledger adapter behind the MeasureResolver seam.
 *
 * It owns no SQL of its own: it unpacks the account ids from the batch of
 * references and forwards them, unchanged, to LedgerBalanceRepository, whose
 * output ([account_id][period_key] => balance) is already the ResolvedSeries
 * shape. This keeps LedgerBalanceRepository the single source of ledger SQL and
 * leaves the engine's query count identical to before the seam existed.
 */
class LedgerMeasureResolver implements MeasureResolver
{
    public function __construct(
        protected LedgerBalanceRepository $ledger,
    ) {}

    public function source(): string
    {
        return MeasureReference::SOURCE_LEDGER;
    }

    public function resolve(array $references, array $periods, ResolutionContext $context): ResolvedSeries
    {
        $accountIds = array_map(
            fn (MeasureReference $reference) => (int) $reference->key,
            $references,
        );

        $balances = $this->ledger->basisBalances(
            $accountIds,
            $periods,
            $context->reportContext,
            $context->basis,
        );

        return new ResolvedSeries($balances);
    }
}
