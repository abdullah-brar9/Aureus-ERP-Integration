<?php

namespace Webkul\Accounting\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\ValueBasis;

/**
 * Single source of truth for reading posted ledger balances.
 *
 * This repository intentionally reuses the exact query shape used by the
 * existing Aureus reporting pages (e.g. ProfitLoss): it joins
 * `accounts_account_move_lines` to `accounts_account_moves` and filters on the
 * parent move's company, state and date, summing the line `balance` grouped by
 * account. No other service in the report engine touches the ledger tables.
 */
class LedgerBalanceRepository
{
    protected string $lineTable = 'accounts_account_move_lines';

    protected string $moveTable = 'accounts_account_moves';

    /**
     * Sum of `balance` per account for the given accounts, within one period,
     * scoped by the report context.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, float> keyed by account_id => summed balance
     */
    public function balancesForAccounts(array $accountIds, ReportPeriod $period, ReportContext $context): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                DB::raw('SUM('.$this->balanceExpression($context).') as balance'),
            ])
            ->whereIn("{$this->lineTable}.account_id", $accountIds)
            ->whereBetween("{$this->moveTable}.date", [
                $period->startDate->toDateString(),
                $period->endDate->toDateString(),
            ])
            ->groupBy("{$this->lineTable}.account_id");

        $this->applyContext($query, $context);

        return $query->get()->mapWithKeys(fn ($row) => [
            (int) $row->account_id => (float) $row->balance,
        ]);
    }

    /**
     * Bulk variant: sum of `balance` per account for many accounts across many
     * periods, returned as [account_id][period_key] => balance.
     *
     * Rows are aggregated per (account, day) in SQL and bucketed into every
     * period whose range contains the day, so overlapping periods (e.g. twelve
     * months plus a full-year total) are each summed correctly.
     *
     * @param  array<int, int>  $accountIds
     * @param  array<int, ReportPeriod>  $periods
     * @return array<int, array<string, float>>
     */
    public function balancesMatrixForAccounts(array $accountIds, array $periods, ReportContext $context): array
    {
        return $this->basisBalances($accountIds, $periods, $context, ValueBasis::MOVEMENT);
    }

    /**
     * Balances per account per period under a given basis, in at most two
     * queries regardless of how many periods are requested:
     *
     *   - movement:        sum of balance with move date inside the period
     *   - closing_balance: cumulative balance up to and including the period end
     *   - opening_balance: cumulative balance up to but excluding the period start
     *
     * @param  array<int, int>  $accountIds
     * @param  array<int, ReportPeriod>  $periods
     * @return array<int, array<string, float>> [account_id][period_key] => balance
     */
    public function basisBalances(array $accountIds, array $periods, ReportContext $context, ValueBasis $basis): array
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));

        $result = [];

        foreach ($accountIds as $accountId) {
            $result[$accountId] = [];
            foreach ($periods as $period) {
                $result[$accountId][$period->key] = 0.0;
            }
        }

        if ($accountIds === [] || $periods === []) {
            return $result;
        }

        $rangeStart = collect($periods)->min(fn (ReportPeriod $p) => $p->startDate->toDateString());
        $rangeEnd = collect($periods)->max(fn (ReportPeriod $p) => $p->endDate->toDateString());

        $carriedForward = $basis === ValueBasis::MOVEMENT
            ? []
            : $this->balancesBefore($accountIds, $rangeStart, $context);

        $dailyByAccount = [];

        foreach ($this->dailyBalances($accountIds, $rangeStart, $rangeEnd, $context) as $row) {
            $dailyByAccount[(int) $row->account_id][substr((string) $row->date, 0, 10)] = (float) $row->balance;
        }

        foreach ($accountIds as $accountId) {
            $daily = $dailyByAccount[$accountId] ?? [];

            foreach ($periods as $period) {
                $start = $period->startDate->toDateString();
                $end = $period->endDate->toDateString();

                $sum = 0.0;

                foreach ($daily as $date => $balance) {
                    $inWindow = match ($basis) {
                        ValueBasis::MOVEMENT        => $date >= $start && $date <= $end,
                        ValueBasis::CLOSING_BALANCE => $date <= $end,
                        ValueBasis::OPENING_BALANCE => $date < $start,
                    };

                    if ($inWindow) {
                        $sum += $balance;
                    }
                }

                if ($basis !== ValueBasis::MOVEMENT) {
                    $sum += (float) ($carriedForward[$accountId] ?? 0.0);
                }

                $result[$accountId][$period->key] = $sum;
            }
        }

        return $result;
    }

    /**
     * Cumulative (opening/closing) balance per account up to and including a
     * date. Used by balance-sheet style period_total reports whose values are
     * point-in-time balances rather than period movements.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, float>
     */
    public function cumulativeBalancesForAccounts(array $accountIds, ReportPeriod $period, ReportContext $context): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                DB::raw('SUM('.$this->balanceExpression($context).') as balance'),
            ])
            ->whereIn("{$this->lineTable}.account_id", $accountIds)
            ->where("{$this->moveTable}.date", '<=', $period->endDate->toDateString())
            ->groupBy("{$this->lineTable}.account_id");

        $this->applyContext($query, $context);

        return $query->get()->mapWithKeys(fn ($row) => [
            (int) $row->account_id => (float) $row->balance,
        ]);
    }

    /**
     * Per-day sums (account_id, date, balance) within a date range, scoped by
     * context and aggregated in SQL so PHP only buckets one row per account per
     * active day.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, object>
     */
    protected function dailyBalances(array $accountIds, string $rangeStart, string $rangeEnd, ReportContext $context): Collection
    {
        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                "{$this->moveTable}.date as date",
                DB::raw('SUM('.$this->balanceExpression($context).') as balance'),
            ])
            ->whereIn("{$this->lineTable}.account_id", $accountIds)
            ->whereBetween("{$this->moveTable}.date", [$rangeStart, $rangeEnd])
            ->groupBy(["{$this->lineTable}.account_id", "{$this->moveTable}.date"]);

        $this->applyContext($query, $context);

        return $query->get();
    }

    /**
     * Cumulative balance per account strictly before a date (the carried-forward
     * amount for opening/closing bases).
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, float> account_id => balance
     */
    protected function balancesBefore(array $accountIds, string $dateExclusive, ReportContext $context): array
    {
        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                DB::raw('SUM('.$this->balanceExpression($context).') as balance'),
            ])
            ->whereIn("{$this->lineTable}.account_id", $accountIds)
            ->where("{$this->moveTable}.date", '<', $dateExclusive)
            ->groupBy("{$this->lineTable}.account_id");

        $this->applyContext($query, $context);

        return $query->get()->mapWithKeys(fn ($row) => [
            (int) $row->account_id => (float) $row->balance,
        ])->all();
    }

    /**
     * Apply the shared company + posted-state filters used by every read.
     */
    protected function applyContext($query, ReportContext $context): void
    {
        if ($context->hasCompanyScope()) {
            $query->whereIn("{$this->moveTable}.company_id", $context->companyIds);
        }

        if ($context->postedOnly) {
            $query->where("{$this->moveTable}.state", MoveState::POSTED->value);
        }

        if ($context->originalCurrencyId !== null) {
            $query->whereRaw($this->originalCurrencyExpression().' = ?', [$context->originalCurrencyId]);
        }
    }

    protected function balanceExpression(ReportContext $context): string
    {
        if ($context->originalCurrencyId !== null) {
            return "COALESCE({$this->lineTable}.original_debit, {$this->lineTable}.debit, 0) - COALESCE({$this->lineTable}.original_credit, {$this->lineTable}.credit, 0)";
        }

        return "{$this->lineTable}.balance";
    }

    protected function originalCurrencyExpression(): string
    {
        return "COALESCE({$this->lineTable}.original_currency_id, {$this->lineTable}.currency_id, {$this->moveTable}.original_currency_id, {$this->moveTable}.currency_id)";
    }
}
