<?php

namespace Webkul\Accounting\Contracts;

use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Models\ReportLine;

/**
 * Supplies values for report lines whose value_source is `external`.
 *
 * Implementations are registered on the ReportValueProviderRegistry under the
 * key stored in the line's `external_provider` column. This is the extension
 * point for non-ledger series (operational KPIs, FX rates, head-counts, ...)
 * without the engine knowing anything about where they come from.
 */
interface ReportValueProvider
{
    /**
     * The value of one line for one period under one company scope.
     */
    public function value(ReportLine $line, ReportPeriod $period, ReportContext $context): float;
}
