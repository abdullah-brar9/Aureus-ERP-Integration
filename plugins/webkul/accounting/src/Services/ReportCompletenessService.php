<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\ReportCompletenessStatus;

class ReportCompletenessService
{
    public function assess(int $companyId, string $dateFrom, string $dateTo): array
    {
        $statementCount = DB::table('accounts_bank_statements')
            ->where('company_id', $companyId)
            ->whereDate('statement_start_date', '<=', $dateTo)
            ->whereDate('statement_end_date', '>=', $dateFrom)
            ->count();

        $awaitingReview = DB::table('accounting_bank_transaction_mappings as mappings')
            ->join('accounts_bank_statement_lines as lines', 'lines.id', '=', 'mappings.statement_line_id')
            ->where('mappings.company_id', $companyId)
            ->whereBetween('lines.transaction_date', [$dateFrom, $dateTo])
            ->whereNotIn('mappings.posting_status', [
                BankPostingStatus::Posted->value,
                BankPostingStatus::MatchedDoNotPost->value,
                BankPostingStatus::DoNotPost->value,
            ])
            ->count();

        $hasOpeningBalances = DB::table('accounts_account_moves')
            ->where('company_id', $companyId)
            ->where('state', MoveState::POSTED->value)
            ->whereDate('date', '<=', $dateTo)
            ->where(function ($query) {
                $query->where('coa_migration_kind', 'opening')
                    ->orWhere(function ($query) {
                        $query->where('accounting_source_type', 'manual_adjustment')
                            ->whereExists(function ($query) {
                                $query->selectRaw('1')
                                    ->from('accounting_manual_adjustments as adjustments')
                                    ->whereColumn('adjustments.id', 'accounts_account_moves.accounting_source_id')
                                    ->where('adjustments.source_classification', 'opening_balances');
                            });
                    });
            })
            ->exists();

        $manualAdjustmentCount = DB::table('accounting_manual_adjustments')
            ->where('company_id', $companyId)
            ->where('approval_status', 'posted')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->where('source_classification', '!=', 'opening_balances')
            ->count();

        $status = match (true) {
            $statementCount === 0 || $awaitingReview > 0 => ReportCompletenessStatus::AwaitingReview,
            ! $hasOpeningBalances                        => ReportCompletenessStatus::MissingOpeningBalances,
            $manualAdjustmentCount === 0                 => ReportCompletenessStatus::MissingNonBankAdjustments,
            default                                      => ReportCompletenessStatus::Complete,
        };

        return [
            'status'                    => $status,
            'statement_count'           => $statementCount,
            'awaiting_review_count'     => $awaitingReview,
            'has_opening_balances'      => $hasOpeningBalances,
            'manual_adjustment_count'   => $manualAdjustmentCount,
            'is_bank_derived'           => $status !== ReportCompletenessStatus::Complete,
            'provisional_label'         => $status === ReportCompletenessStatus::Complete
                ? ReportCompletenessStatus::Complete->value
                : ReportCompletenessStatus::BankDerived->value,
        ];
    }
}
