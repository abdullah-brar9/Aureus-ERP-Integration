<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Enums\BankPostingStatus;

class AccountingReconciliationService
{
    public function __construct(
        protected TrialBalanceService $trialBalance,
        protected DirectCashFlowService $cashFlow,
    ) {}

    public function checks(int $companyId, string $dateFrom, string $dateTo, float $tolerance = 0.01): array
    {
        $checks = [];

        $journalTotals = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereBetween('moves.date', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(SUM(lines.debit), 0) debit, COALESCE(SUM(lines.credit), 0) credit')
            ->first();
        $checks[] = $this->check('Journal debits equal credits', (float) $journalTotals->debit, (float) $journalTotals->credit, $tolerance, 'Journal Entries');

        $trialBalance = $this->trialBalance->compute($companyId, $dateFrom, $dateTo);
        $checks[] = $this->check(
            'Trial Balance closing debits equal credits',
            (float) $trialBalance['totals']['closing_debit'],
            (float) $trialBalance['totals']['closing_credit'],
            $tolerance,
            'Trial Balance / Journal Entries',
        );

        $latestStatements = DB::table('accounts_bank_statements')
            ->where('company_id', $companyId)
            ->whereDate('statement_end_date', '<=', $dateTo)
            ->orderByDesc('statement_end_date')
            ->orderByDesc('id')
            ->get()
            ->unique(fn ($statement): string => implode(':', [
                $statement->bank_gl_account_id,
                $statement->bank_account_number,
                $statement->currency_id,
            ]));

        foreach ($latestStatements->groupBy('bank_gl_account_id') as $bankGlAccountId => $statements) {
            if (! $statements->contains(fn ($statement): bool => $statement->statement_end_date >= $dateFrom)) {
                continue;
            }

            $actual = (float) DB::table('accounts_account_move_lines as lines')
                ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
                ->where('moves.company_id', $companyId)
                ->where('moves.state', MoveState::POSTED->value)
                ->whereDate('moves.date', '<=', $dateTo)
                ->where('lines.account_id', $bankGlAccountId)
                ->sum('lines.balance');

            $expected = (float) $statements->sum(function ($statement): float {
                return (float) ($statement->company_closing_balance ?? $statement->closing_balance);
            });
            $sourceNames = $statements->pluck('bank_name')->filter()->unique()->implode(', ');
            $checks[] = $this->check(
                "Bank GL {$bankGlAccountId} ledger equals aggregate latest statement closings ({$sourceNames})",
                $actual,
                $expected,
                $tolerance,
                'Bank Statements / Transaction Mapping',
            );
        }

        $balances = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->join('accounts_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('moves.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('moves.date', '<=', $dateTo)
            ->groupBy('accounts.account_type')
            ->selectRaw('accounts.account_type, SUM(lines.balance) as balance')
            ->get()
            ->pluck('balance', 'account_type');

        $assets = $this->sumTypes($balances, array_keys(AccountType::assets()));
        $liabilities = -$this->sumTypes($balances, array_keys(AccountType::liabilities()));
        $equity = -$this->sumTypes($balances, array_keys(AccountType::equity()));
        $income = -$this->sumTypes($balances, array_keys(AccountType::income()));
        $expenses = $this->sumTypes($balances, array_keys(AccountType::expenses()));
        $checks[] = $this->check('Assets equal Liabilities plus Equity', $assets, $liabilities + $equity + $income - $expenses, $tolerance, 'Balance Sheet / Manual Adjustments');

        $cashFlow = $this->cashFlow->calculate($companyId, $dateFrom, $dateTo);
        $checks[] = $this->check('Cash Flow ending cash equals Balance Sheet cash', $cashFlow['ending_cash'], $cashFlow['ledger_cash'], $tolerance, 'Cash Flow / Opening Balances');

        $missingGl = DB::table('accounting_bank_transaction_mappings')
            ->where('company_id', $companyId)
            ->whereNotIn('posting_status', [BankPostingStatus::DoNotPost->value, BankPostingStatus::MatchedDoNotPost->value])
            ->where(function ($query) {
                $query->whereNull('bank_gl_account_id')->orWhereNull('offset_account_id');
            })->count();
        $checks[] = $this->check('Every mapped GL code exists', $missingGl, 0, 0, 'Transaction Mapping / Chart of Accounts');

        $totalMappings = DB::table('accounting_bank_transaction_mappings')->where('company_id', $companyId)->count();
        $resolvedMappings = DB::table('accounting_bank_transaction_mappings')->where('company_id', $companyId)
            ->where(function ($query) {
                $query->whereIn('review_status', ['approved', 'posted', 'matched_transfer', 'do_not_post', 'rejected'])
                    ->orWhereIn('posting_status', ['posted', 'matched_do_not_post', 'do_not_post']);
            })->count();
        $checks[] = $this->check('Every imported transaction is reviewed', $resolvedMappings, $totalMappings, 0, 'Transaction Mapping');

        $incompleteTransfers = DB::table('accounting_bank_transfer_matches as transfers')
            ->leftJoin('accounting_bank_transaction_mappings as mappings', 'mappings.transfer_match_id', '=', 'transfers.id')
            ->where('transfers.company_id', $companyId)
            ->select('transfers.id')
            ->groupBy('transfers.id')
            ->havingRaw('COUNT(mappings.id) != 2')
            ->count();
        $checks[] = $this->check('Transfer pairs are complete', $incompleteTransfers, 0, 0, 'Transfer Matching');

        $duplicatePostings = DB::table('accounts_account_moves')
            ->where('company_id', $companyId)
            ->whereNotNull('accounting_source_type')
            ->whereNotNull('accounting_source_id')
            ->groupBy('accounting_source_type', 'accounting_source_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $checks[] = $this->check('No transaction is posted twice', $duplicatePostings, 0, 0, 'Journal Entries');

        $failedStatements = DB::table('accounts_bank_statements')->where('company_id', $companyId)
            ->whereRaw('ABS(opening_balance + total_credits - total_debits - closing_balance) > ?', [$tolerance])->count();
        $checks[] = $this->check('Statement opening plus credits minus debits equals closing', $failedStatements, 0, 0, 'Bank Statements');

        $unmappedPosted = DB::table('accounting_bank_transaction_mappings as mappings')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'mappings.move_id')
            ->where('mappings.company_id', $companyId)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereIn('mappings.review_status', ['unmapped', 'suggested', 'needs_review'])
            ->count();
        $checks[] = $this->check('No unmapped posted transactions', $unmappedPosted, 0, 0, 'Transaction Mapping / Journal Entries');

        return $checks;
    }

    protected function sumTypes($balances, array $types): float
    {
        return round(array_sum(array_map(fn (string $type) => (float) ($balances[$type] ?? 0), $types)), 2);
    }

    protected function check(string $name, float|int $actual, float|int $expected, float $tolerance, string $whereToFix): array
    {
        $difference = round((float) $actual - (float) $expected, 2);

        return [
            'name'         => $name,
            'actual'       => round((float) $actual, 2),
            'expected'     => round((float) $expected, 2),
            'difference'   => $difference,
            'tolerance'    => $tolerance,
            'status'       => abs($difference) <= $tolerance ? 'PASS' : 'FAIL',
            'where_to_fix' => $whereToFix,
        ];
    }
}
