<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Enums\CashFlowCategory;

class DirectCashFlowService
{
    public function calculate(int $companyId, string $dateFrom, string $dateTo): array
    {
        $categories = collect(CashFlowCategory::cases())->mapWithKeys(fn (CashFlowCategory $category) => [$category->value => 0.0])->all();

        $bankAccountIds = DB::table('accounts_bank_statements')
            ->where('company_id', $companyId)
            ->whereNotNull('bank_gl_account_id')
            ->distinct()
            ->pluck('bank_gl_account_id')
            ->all();

        if ($bankAccountIds !== []) {
            $rows = DB::table('accounts_account_move_lines as lines')
                ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
                ->where('moves.company_id', $companyId)
                ->where('moves.state', MoveState::POSTED->value)
                ->where('moves.accounting_source_type', 'bank_mapping')
                ->whereBetween('moves.date', [$dateFrom, $dateTo])
                ->whereIn('lines.account_id', $bankAccountIds)
                ->whereNotNull('moves.cash_flow_category')
                ->groupBy('moves.cash_flow_category')
                ->selectRaw('moves.cash_flow_category as category, SUM(lines.balance) as amount')
                ->get();

            foreach ($rows as $row) {
                $categories[$row->category] = round((float) $row->amount, 2);
            }
        }

        $statementOpeningCash = (float) DB::table('accounts_bank_statements')
            ->where('company_id', $companyId)
            ->whereDate('statement_start_date', $dateFrom)
            ->sum('opening_balance');
        $openingCash = $bankAccountIds === [] ? 0.0 : (float) DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('moves.date', '<', $dateFrom)
            ->whereIn('lines.account_id', $bankAccountIds)
            ->sum('lines.balance');
        $netChange = round(array_sum($categories), 2);
        $endingCash = round($openingCash + $netChange, 2);

        $ledgerCash = $bankAccountIds === [] ? 0.0 : (float) DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('moves.date', '<=', $dateTo)
            ->whereIn('lines.account_id', $bankAccountIds)
            ->sum('lines.balance');

        return [
            'categories'             => $categories,
            'opening_cash'           => round($openingCash, 2),
            'statement_opening_cash' => round($statementOpeningCash, 2),
            'net_change'             => $netChange,
            'ending_cash'            => $endingCash,
            'ledger_cash'            => round($ledgerCash, 2),
            'difference'             => round($endingCash - $ledgerCash, 2),
        ];
    }
}
