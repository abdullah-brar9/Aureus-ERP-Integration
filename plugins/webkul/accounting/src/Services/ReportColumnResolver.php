<?php

namespace Webkul\Accounting\Services;

use Carbon\Carbon;
use RuntimeException;
use Webkul\Accounting\Data\ReportColumnSpec;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Resolves a template's column definitions against a run year into concrete
 * ReportColumnSpec instances.
 *
 * Templates without explicit column definitions fall back to a default set
 * derived from layout_type — twelve months plus a full-year total for
 * monthly_matrix, one full-year column for period_total — which is exactly the
 * Stage 3 behaviour, so pre-existing templates render unchanged.
 */
class ReportColumnResolver
{
    /**
     * @return array<int, ReportColumnSpec>
     */
    public function resolve(ReportTemplate $template, int $year, ReportContext $context): array
    {
        $columns = $template->columns()->get();

        if ($columns->isEmpty()) {
            return $this->defaultsForLayout($template, $year);
        }

        return $columns
            ->map(fn (ReportColumn $column) => $this->resolveColumn($column, $year))
            ->all();
    }

    protected function resolveColumn(ReportColumn $column, int $year): ReportColumnSpec
    {
        $key = 'col_'.$column->id;
        $type = $column->column_type instanceof ColumnType
            ? $column->column_type
            : ColumnType::from((string) $column->column_type);

        if ($type === ColumnType::SPACER) {
            return ReportColumnSpec::spacer($key);
        }

        $columnYear = $year + (int) $column->year_offset;

        $period = match ($type) {
            ColumnType::MONTH     => $this->monthPeriod($key, $column, $columnYear),
            ColumnType::RANGE     => $this->rangePeriod($key, $column, $columnYear),
            ColumnType::FULL_YEAR => $this->fullYearPeriod($key, $columnYear),
            ColumnType::SPACER    => null,
        };

        return new ReportColumnSpec(
            key: $key,
            label: $column->label ?? $period->label,
            period: $period,
            companyIds: $column->company_id !== null ? [(int) $column->company_id] : null,
            isConsolidated: (bool) $column->is_consolidated,
        );
    }

    protected function monthPeriod(string $key, ReportColumn $column, int $year): ReportPeriod
    {
        $month = $this->requireMonth($column, (int) $column->start_month, 'start_month');

        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return ReportPeriod::make($key, $start->format('M'), $start, $start->copy()->endOfMonth());
    }

    protected function rangePeriod(string $key, ReportColumn $column, int $year): ReportPeriod
    {
        $startMonth = $this->requireMonth($column, (int) $column->start_month, 'start_month');
        $endMonth = $this->requireMonth($column, (int) $column->end_month, 'end_month');

        if ($endMonth < $startMonth) {
            throw new RuntimeException(
                "Report column {$column->id} has a range ending before it starts ({$startMonth}..{$endMonth})."
            );
        }

        $start = Carbon::create($year, $startMonth, 1)->startOfMonth();
        $end = Carbon::create($year, $endMonth, 1)->endOfMonth();

        return ReportPeriod::make($key, $start->format('M').'-'.$end->format('M'), $start, $end);
    }

    protected function fullYearPeriod(string $key, int $year): ReportPeriod
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();

        return ReportPeriod::make($key, (string) $year, $start, $start->copy()->endOfYear());
    }

    protected function requireMonth(ReportColumn $column, int $month, string $field): int
    {
        if ($month < 1 || $month > 12) {
            throw new RuntimeException(
                "Report column {$column->id} has an invalid {$field} ({$month}); expected 1-12."
            );
        }

        return $month;
    }

    /**
     * The Stage 3 default column sets, keyed by the period keys the engine used
     * before columns existed so cached callers see identical value maps.
     *
     * @return array<int, ReportColumnSpec>
     */
    protected function defaultsForLayout(ReportTemplate $template, int $year): array
    {
        $layout = $template->layout_type instanceof LayoutType
            ? $template->layout_type
            : LayoutType::from((string) $template->layout_type);

        if ($layout === LayoutType::MONTHLY_MATRIX) {
            $periods = ReportPeriod::monthsOfYear($year);
            $periods[] = ReportPeriod::fullYear($year);
        } else {
            $periods = [ReportPeriod::fullYear($year)];
        }

        return array_map(
            fn (ReportPeriod $period) => new ReportColumnSpec($period->key, $period->label, $period),
            $periods,
        );
    }
}
