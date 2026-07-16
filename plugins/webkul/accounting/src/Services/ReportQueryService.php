<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Public entry point for producing a rendered report.
 *
 * Responsibilities:
 *   - derive the period set from the template's layout_type
 *   - choose movement vs cumulative balances
 *   - run the calculation engine
 *   - cache the (pure) result and invalidate it when the template changes
 *
 * The UI (Stage 4 designer preview) and the report/export pages (Stage 5) both
 * call this service, so no ledger or formula logic ever lives in a Filament
 * page.
 */
class ReportQueryService
{
    /**
     * Default number of seconds a computed report is cached.
     */
    public int $cacheTtlSeconds = 900;

    public function __construct(
        protected ReportCalculationEngine $engine,
    ) {}

    /**
     * Produce the report values for a template and year, scoped by context.
     *
     * @return Collection<int, ReportLineValue>
     */
    public function getReport(ReportTemplate $template, int $year, ReportContext $context, bool $useCache = true): Collection
    {
        $periods    = $this->periodsFor($template, $year);
        $cumulative = $this->usesCumulativeBalances($template);

        if (! $useCache) {
            return $this->engine->calculate($template, $periods, $context, $cumulative);
        }

        $key = ReportCacheKey::for($template, $periods, $context, $cumulative);

        /** @var array<int, array<string, mixed>> $cached */
        $cached = Cache::remember($key, $this->cacheTtlSeconds, function () use ($template, $periods, $context, $cumulative) {
            return $this->engine
                ->calculate($template, $periods, $context, $cumulative)
                ->map(fn (ReportLineValue $v) => $v->toArray())
                ->all();
        });

        return $this->hydrate($cached);
    }

    /**
     * Uncached convenience used by the designer preview.
     *
     * @return Collection<int, ReportLineValue>
     */
    public function previewReport(ReportTemplate $template, int $year, ReportContext $context): Collection
    {
        return $this->getReport($template, $year, $context, useCache: false);
    }

    /**
     * Forget any cached result for this template across common scopes is not
     * required because the cache key includes the template's updated_at; a saved
     * change yields a new key automatically. This method remains for callers who
     * want to proactively drop a specific run's cache.
     *
     * @param  array<int, ReportPeriod>  $periods
     */
    public function forget(ReportTemplate $template, array $periods, ReportContext $context, bool $cumulative): void
    {
        Cache::forget(ReportCacheKey::for($template, $periods, $context, $cumulative));
    }

    /**
     * Derive the period columns from the template layout.
     *
     * @return array<int, ReportPeriod>
     */
    public function periodsFor(ReportTemplate $template, int $year): array
    {
        $layout = $template->layout_type instanceof LayoutType
            ? $template->layout_type
            : LayoutType::from((string) $template->layout_type);

        if ($layout === LayoutType::MONTHLY_MATRIX) {
            $months = ReportPeriod::monthsOfYear($year);
            $months[] = ReportPeriod::fullYear($year);

            return $months;
        }

        // period_total: a single full-year column.
        return [ReportPeriod::fullYear($year)];
    }

    /**
     * Balance-sheet style reports report point-in-time balances (cumulative);
     * profit-and-loss / cashflow style reports report period movements.
     *
     * The distinction is data-driven via currency/entity/layout signals rather
     * than a hardcoded per-report rule: a period_total layout is treated as a
     * balance-sheet snapshot (cumulative), a monthly_matrix as movements. Stage
     * 4 can extend the template with an explicit flag if a report needs to
     * override this; until then the layout drives it.
     */
    protected function usesCumulativeBalances(ReportTemplate $template): bool
    {
        $layout = $template->layout_type instanceof LayoutType
            ? $template->layout_type
            : LayoutType::from((string) $template->layout_type);

        return $layout === LayoutType::PERIOD_TOTAL;
    }

    /**
     * Rebuild ReportLineValue objects from a cached array payload.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, ReportLineValue>
     */
    protected function hydrate(array $rows): Collection
    {
        return collect($rows)->map(function (array $row) {
            return new ReportLineValue(
                lineId:      (int) $row['line_id'],
                parentId:    $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                lineType:    \Webkul\Accounting\Enums\LineType::from((string) $row['line_type']),
                caption:     $row['caption'] !== null ? (string) $row['caption'] : null,
                code:        $row['code'] !== null ? (string) $row['code'] : null,
                isVisible:   (bool) $row['is_visible'],
                isBold:      (bool) $row['is_bold'],
                indentLevel: (int) $row['indent_level'],
                sort:        (int) $row['sort'],
                values:      array_map('floatval', $row['values'] ?? []),
            );
        })->values();
    }
}
