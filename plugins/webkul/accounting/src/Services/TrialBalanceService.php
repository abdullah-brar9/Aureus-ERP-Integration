<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;

/**
 * Computes a Trial Balance strictly from posted account-move lines, following
 * the agreed formula. Two aggregate SQL queries total (before-date opening +
 * in-range movement/adjustment) — never a query per account. Adjustment
 * postings are separated by persistent migration/source fields, not by date.
 *
 * openingNet     = Σ(debit-credit) with move.date < fromDate
 * movementDr/Cr  = Σ debit/credit in [fromDate,toDate], kind <> 'adjustment'
 * adjustmentDr/Cr= Σ debit/credit in [fromDate,toDate], kind  = 'adjustment'
 * closingNet     = openingNet + movementDr - movementCr + adjustmentDr - adjustmentCr
 * debit/credit columns = max(net,0) / max(-net,0)
 */
class TrialBalanceService
{
    protected string $lineTable = 'accounts_account_move_lines';

    protected string $moveTable = 'accounts_account_moves';

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function compute(int $companyId, string $fromDate, string $toDate, array $filters = []): array
    {
        $postedOnly = $filters['posted_only'] ?? true;
        $journalIds = $filters['journal_ids'] ?? [];
        $accountIds = $filters['account_ids'] ?? [];
        $includeZero = $filters['include_zero'] ?? false;
        $includeGroups = $filters['include_groups'] ?? false;

        $opening = $this->openingNets($companyId, $fromDate, $postedOnly, $journalIds);
        $range = $this->rangeSums($companyId, $fromDate, $toDate, $postedOnly, $journalIds);

        // Leaf accounts of the company (postable), optionally filtered.
        $accounts = Account::query()
            ->postable()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->when($accountIds !== [], fn ($q) => $q->whereIn('id', $accountIds))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_group', 'parent_id']);

        $rows = [];
        $totals = $this->emptyTotals();

        foreach ($accounts as $account) {
            $openingNet = (float) ($opening[$account->id] ?? 0.0);
            $movementDebit = (float) ($range[$account->id]['mov_debit'] ?? 0.0);
            $movementCredit = (float) ($range[$account->id]['mov_credit'] ?? 0.0);
            $adjustmentDebit = (float) ($range[$account->id]['adj_debit'] ?? 0.0);
            $adjustmentCredit = (float) ($range[$account->id]['adj_credit'] ?? 0.0);

            $closingNet = $openingNet
                + $movementDebit - $movementCredit
                + $adjustmentDebit - $adjustmentCredit;

            $row = [
                'account_id'        => $account->id,
                'code'              => $account->code,
                'name'              => $account->name,
                'is_group'          => false,
                'opening_debit'     => $this->pos($openingNet),
                'opening_credit'    => $this->neg($openingNet),
                'movement_debit'    => round($movementDebit, 2),
                'movement_credit'   => round($movementCredit, 2),
                'adjustment_debit'  => round($adjustmentDebit, 2),
                'adjustment_credit' => round($adjustmentCredit, 2),
                'closing_debit'     => $this->pos($closingNet),
                'closing_credit'    => $this->neg($closingNet),
            ];

            $nonZero = $this->rowHasValue($row);

            if (! $includeZero && ! $nonZero) {
                continue;
            }

            $rows[] = $row;

            // Grand totals accumulate LEAF rows only (no double counting).
            foreach ($this->valueKeys() as $k) {
                $totals[$k] += $row[$k];
            }
        }

        $totals = array_map(fn ($v) => round($v, 2), $totals);
        $totals['difference'] = round($totals['closing_debit'] - $totals['closing_credit'], 2);

        if ($includeGroups) {
            $rows = $this->withGroupAggregates($companyId, $rows);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array<int, float> account_id => net before fromDate
     */
    protected function openingNets(int $companyId, string $fromDate, bool $postedOnly, array $journalIds): array
    {
        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                DB::raw("SUM({$this->lineTable}.debit - {$this->lineTable}.credit) as net"),
            ])
            ->where("{$this->moveTable}.company_id", $companyId)
            ->where("{$this->moveTable}.date", '<', $fromDate)
            ->groupBy("{$this->lineTable}.account_id");

        $this->applyPostedJournal($query, $postedOnly, $journalIds);

        return $query->get()->mapWithKeys(fn ($r) => [(int) $r->account_id => (float) $r->net])->all();
    }

    /**
     * @return array<int, array<string, float>>
     */
    protected function rangeSums(int $companyId, string $fromDate, string $toDate, bool $postedOnly, array $journalIds): array
    {
        $adj = "({$this->moveTable}.coa_migration_kind = 'adjustment'"
            ." OR {$this->moveTable}.accounting_source_type = 'manual_adjustment')";

        $query = DB::table($this->lineTable)
            ->join($this->moveTable, "{$this->lineTable}.move_id", '=', "{$this->moveTable}.id")
            ->select([
                "{$this->lineTable}.account_id",
                DB::raw("SUM(CASE WHEN {$adj} THEN 0 ELSE {$this->lineTable}.debit END) as mov_debit"),
                DB::raw("SUM(CASE WHEN {$adj} THEN 0 ELSE {$this->lineTable}.credit END) as mov_credit"),
                DB::raw("SUM(CASE WHEN {$adj} THEN {$this->lineTable}.debit ELSE 0 END) as adj_debit"),
                DB::raw("SUM(CASE WHEN {$adj} THEN {$this->lineTable}.credit ELSE 0 END) as adj_credit"),
            ])
            ->where("{$this->moveTable}.company_id", $companyId)
            ->whereBetween("{$this->moveTable}.date", [$fromDate, $toDate])
            ->groupBy("{$this->lineTable}.account_id");

        $this->applyPostedJournal($query, $postedOnly, $journalIds);

        return $query->get()->mapWithKeys(fn ($r) => [(int) $r->account_id => [
            'mov_debit'  => (float) $r->mov_debit,
            'mov_credit' => (float) $r->mov_credit,
            'adj_debit'  => (float) $r->adj_debit,
            'adj_credit' => (float) $r->adj_credit,
        ]])->all();
    }

    protected function applyPostedJournal($query, bool $postedOnly, array $journalIds): void
    {
        if ($postedOnly) {
            $query->where("{$this->moveTable}.state", MoveState::POSTED->value);
        }
        if ($journalIds !== []) {
            $query->whereIn("{$this->moveTable}.journal_id", $journalIds);
        }
    }

    /**
     * Add non-postable group rows that aggregate their descendant leaves. These
     * are display-only and are NOT added to grand totals.
     *
     * @param  array<int, array<string, mixed>>  $leafRows
     * @return array<int, array<string, mixed>>
     */
    protected function withGroupAggregates(int $companyId, array $leafRows): array
    {
        $groups = Account::query()
            ->where('is_group', true)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->get(['id', 'code', 'name', 'parent_id']);

        if ($groups->isEmpty()) {
            return $leafRows;
        }

        // Map leaf account -> its ancestor group ids (walk parent_id).
        $allById = Account::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->get(['id', 'parent_id'])->keyBy('id');

        $groupTotals = [];
        foreach ($leafRows as $row) {
            $ancestor = $allById[$row['account_id']]->parent_id ?? null;
            while ($ancestor !== null) {
                foreach ($this->valueKeys() as $k) {
                    $groupTotals[$ancestor][$k] = ($groupTotals[$ancestor][$k] ?? 0.0) + $row[$k];
                }
                $ancestor = $allById[$ancestor]->parent_id ?? null;
            }
        }

        $groupRows = [];
        foreach ($groups as $group) {
            $vals = $groupTotals[$group->id] ?? [];
            $groupRows[] = array_merge([
                'account_id' => $group->id,
                'code'       => $group->code,
                'name'       => $group->name,
                'is_group'   => true,
            ], array_map(fn ($k) => round($vals[$k] ?? 0.0, 2), array_combine($this->valueKeys(), $this->valueKeys())));
        }

        return array_merge($groupRows, $leafRows);
    }

    protected function pos(float $net): float
    {
        return round(max($net, 0.0), 2);
    }

    protected function neg(float $net): float
    {
        return round(max(-$net, 0.0), 2);
    }

    /**
     * @return array<int, string>
     */
    protected function valueKeys(): array
    {
        return [
            'opening_debit', 'opening_credit',
            'movement_debit', 'movement_credit',
            'adjustment_debit', 'adjustment_credit',
            'closing_debit', 'closing_credit',
        ];
    }

    protected function emptyTotals(): array
    {
        return array_fill_keys($this->valueKeys(), 0.0);
    }

    protected function rowHasValue(array $row): bool
    {
        foreach ($this->valueKeys() as $k) {
            if (abs($row[$k]) > 0.005) {
                return true;
            }
        }

        return false;
    }
}
