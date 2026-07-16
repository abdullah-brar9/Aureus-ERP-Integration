<?php

namespace Webkul\Accounting\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;

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
     * @return Collection<int, float>  keyed by account_id => summed balance
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
                DB::raw("SUM({$this->lineTable}.balance) as balance"),
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
     * periods in a single query, returned as [account_id][period_key] => balance.
     *
     * This is what the monthly-matrix engine uses so that a twelve-column report
     * costs one query instead of twelve.
     *
     * @param  array<int, int>  $accountIds
     * @param  array<int, ReportPeriod>  $periods
     * @return array<int, array<string, float>>
     */
    public function balancesMatrixForAccounts(array $accountIds, array $periods, ReportContext $context): array
    {
        $result = [];

        foreach ($accountIds as $accountId) {
            $result[(int) $accountId] = [];
            foreach ($periods as $period) {
                $result[(int) $accountId][$period->key] = 0.0;
            }
        }

        if ($accountIds === [] || $periods === []) {
            return $result;
        }

        $rangeStart = collect($periods)->min(fn (ReportPeriod $p) => $p->startDate->toDateString());
        $rangeEnd   = collect($periods)->max(fn (ReportPeriod $p) => $p->endDate->toDateString());

        $rows = $this->rawLinesInRange($accountIds, $rangeStart, $rangeEnd, $context);

        foreach ($rows as $row) {
            $accountId = (int) $row->account_id;
            $date      = $row->date;

            foreach ($periods as $period) {
                if ($date >= $period->startDate->toDateString() && $date <= $period->endDate->toDateString()) {
                    $result[$accountId][$period->key] += (float) $row->balance;
                    break;
                }
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
                DB::raw("SUM({$this->lineTable}.balance) as balance"),
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
     * Raw per-line rows (account_id, date, balance) within a date range,
     * scoped by context. Kept protected so the ledger query shape lives in one
     * place; the matrix method buckets these rows into periods in PHP.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, object>
     */
    protected function rawLinesInRange(array $accountIds, string $rangeStart, string $rangeEnd, ReportContext $context): Collection
    {
        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                "{$this->moveTable}.date as date",
                "{$this->lineTable}.balance as balance",
            ])
            ->whereIn("{$this->lineTable}.account_id", $accountIds)
            ->whereBetween("{$this->moveTable}.date", [$rangeStart, $rangeEnd]);

        $this->applyContext($query, $context);

        return $query->get();
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
    }
}
