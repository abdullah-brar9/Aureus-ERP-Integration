<?php

namespace Webkul\Accounting\Services;

use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Builds a deterministic cache key for a report run.
 *
 * The key incorporates the template identity and its version and updated_at, so
 * any edit to the template (layout, lines, formulas — all of which touch the
 * template's updated_at via the designer save flow) naturally yields a new key
 * and invalidates stale results. Period and company scope are included so
 * different views of the same template cache independently.
 */
class ReportCacheKey
{
    /**
     * @param  array<int, ReportPeriod>  $periods
     */
    public static function for(ReportTemplate $template, array $periods, ReportContext $context, bool $cumulative): string
    {
        $periodPart = implode('|', array_map(
            fn (ReportPeriod $p) => $p->key.':'.$p->startDate->toDateString().':'.$p->endDate->toDateString(),
            $periods,
        ));

        $companyPart = implode(',', $context->companyIds);

        $stamp = optional($template->updated_at)->getTimestamp() ?? 0;

        $raw = implode(';', [
            'tpl='.$template->id,
            'ver='.$template->version,
            'upd='.$stamp,
            'cum='.($cumulative ? '1' : '0'),
            'posted='.($context->postedOnly ? '1' : '0'),
            'companies='.$companyPart,
            'periods='.$periodPart,
        ]);

        return 'accounting:report:'.md5($raw);
    }
}
