<?php

namespace Webkul\Accounting\Data;

use Carbon\Carbon;

/**
 * An immutable, named reporting date range.
 *
 * Used as the column unit of a report: a period_total report has a single
 * ReportPeriod; a monthly_matrix report has one per month plus a "total".
 */
final class ReportPeriod
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly Carbon $startDate,
        public readonly Carbon $endDate,
    ) {}

    public static function make(string $key, string $label, Carbon $startDate, Carbon $endDate): self
    {
        return new self($key, $label, $startDate->copy()->startOfDay(), $endDate->copy()->endOfDay());
    }

    /**
     * Build the twelve calendar-month periods for a given year.
     *
     * @return array<int, self>
     */
    public static function monthsOfYear(int $year): array
    {
        $periods = [];

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $end   = $start->copy()->endOfMonth();

            $periods[] = self::make(
                sprintf('%04d-%02d', $year, $month),
                $start->format('M'),
                $start,
                $end,
            );
        }

        return $periods;
    }

    /**
     * A single period spanning the full year.
     */
    public static function fullYear(int $year): self
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end   = $start->copy()->endOfYear();

        return self::make((string) $year, (string) $year, $start, $end);
    }

    public function toArray(): array
    {
        return [
            'key'        => $this->key,
            'label'      => $this->label,
            'start_date' => $this->startDate->toDateString(),
            'end_date'   => $this->endDate->toDateString(),
        ];
    }
}
