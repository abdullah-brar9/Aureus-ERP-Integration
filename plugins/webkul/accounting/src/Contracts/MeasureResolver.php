<?php

namespace Webkul\Accounting\Contracts;

use Webkul\Accounting\Data\MeasureReference;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Data\ResolutionContext;
use Webkul\Accounting\Data\ResolvedSeries;

/**
 * The single interface through which the report engine requests values, one
 * batch at a time, regardless of the underlying source (ledger today; imported
 * datasets, manual inputs and external APIs in later phases).
 *
 * `resolve()` is intentionally batch-oriented: it takes every reference for one
 * (scope, basis) group at once so a resolver can answer with the minimum number
 * of queries — the ledger resolver forwards the whole batch to a single
 * repository call, preserving the engine's existing query count exactly.
 */
interface MeasureResolver
{
    /**
     * The source key this resolver answers for (e.g. "ledger"). The registry
     * routes a MeasureReference to the resolver whose source matches.
     */
    public function source(): string;

    /**
     * Resolve every reference for the given periods under one context.
     *
     * @param  array<int, MeasureReference>  $references
     * @param  array<int, ReportPeriod>  $periods
     */
    public function resolve(array $references, array $periods, ResolutionContext $context): ResolvedSeries;
}
