<?php

use Carbon\Carbon;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Support\Models\Company;

/**
 * Seeds one posted move line on a given date/company/account. The MoveLine
 * `saving` hook derives account_id from the journal's default account, so the
 * journal is created with the target account as its default (see STAGE3_BUGFIX).
 */
function ledgerSeed(Company $company, Account $account, float $balance, string $date): void
{
    $journal = Journal::factory()->create([
        'company_id'         => $company->id,
        'default_account_id' => $account->id,
    ]);

    $move = Move::factory()->create([
        'company_id' => $company->id,
        'journal_id' => $journal->id,
        'state'      => MoveState::POSTED,
        'date'       => $date,
    ]);

    MoveLine::factory()->create([
        'move_id'      => $move->id,
        'journal_id'   => $journal->id,
        'company_id'   => $company->id,
        'account_id'   => $account->id,
        'balance'      => $balance,
        'debit'        => $balance >= 0 ? $balance : 0,
        'credit'       => $balance < 0 ? -$balance : 0,
        'parent_state' => MoveState::POSTED,
        'date'         => $date,
    ]);
}

function ledgerMonth(int $year, int $month): ReportPeriod
{
    $start = Carbon::create($year, $month, 1)->startOfMonth();

    return ReportPeriod::make(sprintf('%04d-%02d', $year, $month), $start->format('M'), $start, $start->copy()->endOfMonth());
}

it('buckets overlapping periods correctly in the movement matrix', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    ledgerSeed($company, $account, 100.0, '2025-01-15');
    ledgerSeed($company, $account, 200.0, '2025-02-15');

    $periods = [ledgerMonth(2025, 1), ledgerMonth(2025, 2)];
    $periods[] = ReportPeriod::fullYear(2025);

    $matrix = (new LedgerBalanceRepository)->balancesMatrixForAccounts(
        [$account->id],
        $periods,
        ReportContext::forCompany($company->id),
    );

    // A ledger day must count toward every period containing it: the
    // full-year total column overlaps the month columns.
    expect($matrix[$account->id]['2025-01'])->toBe(100.0)
        ->and($matrix[$account->id]['2025-02'])->toBe(200.0)
        ->and($matrix[$account->id]['2025'])->toBe(300.0);
});

it('computes movement, opening and closing bases over the same ledger', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    // Carried forward from before the requested range, plus in-range activity.
    ledgerSeed($company, $account, 1000.0, '2024-12-20');
    ledgerSeed($company, $account, 100.0, '2025-01-10');
    ledgerSeed($company, $account, 200.0, '2025-02-10');

    $periods = [ledgerMonth(2025, 1), ledgerMonth(2025, 2)];
    $context = ReportContext::forCompany($company->id);

    $repository = new LedgerBalanceRepository;

    $movement = $repository->basisBalances([$account->id], $periods, $context, ValueBasis::MOVEMENT);
    $opening = $repository->basisBalances([$account->id], $periods, $context, ValueBasis::OPENING_BALANCE);
    $closing = $repository->basisBalances([$account->id], $periods, $context, ValueBasis::CLOSING_BALANCE);

    expect($movement[$account->id]['2025-01'])->toBe(100.0)
        ->and($movement[$account->id]['2025-02'])->toBe(200.0)
        ->and($opening[$account->id]['2025-01'])->toBe(1000.0)
        ->and($opening[$account->id]['2025-02'])->toBe(1100.0)
        ->and($closing[$account->id]['2025-01'])->toBe(1100.0)
        ->and($closing[$account->id]['2025-02'])->toBe(1300.0);
});

it('scopes basis balances by company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $account = Account::factory()->create();

    ledgerSeed($companyA, $account, 300.0, '2025-03-10');
    ledgerSeed($companyB, $account, 900.0, '2025-03-10');

    $periods = [ledgerMonth(2025, 3)];

    $repository = new LedgerBalanceRepository;

    $scoped = $repository->basisBalances([$account->id], $periods, ReportContext::forCompany($companyA->id), ValueBasis::MOVEMENT);
    $both = $repository->basisBalances([$account->id], $periods, ReportContext::forCompanies([$companyA->id, $companyB->id]), ValueBasis::MOVEMENT);

    expect($scoped[$account->id]['2025-03'])->toBe(300.0)
        ->and($both[$account->id]['2025-03'])->toBe(1200.0);
});
