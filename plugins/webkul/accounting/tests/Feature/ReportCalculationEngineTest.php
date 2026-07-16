<?php

use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\AccountBindingService;
use Webkul\Accounting\Services\Formula\FormulaEvaluator;
use Webkul\Accounting\Services\ReportCalculationEngine;
use Webkul\Support\Models\Company;

/**
 * Build a posted move on a given date/company with a line of the given balance
 * against the given account.
 *
 * Note: the MoveLine model's `saving` hook recomputes several fields from the
 * parent move — including `account_id` via computeAccountId(), which for a
 * plain journal entry (display_type = null) resolves the account from the
 * journal's default_account_id. To make a line land on a specific account, the
 * move's journal must therefore have that account as its default. We create the
 * journal accordingly so the persisted line genuinely belongs to $account,
 * exactly as a real ledger entry would.
 */
function postLine(Company $company, Account $account, float $balance, string $date, MoveState $state = MoveState::POSTED): void
{
    $journal = Journal::factory()->create([
        'company_id'         => $company->id,
        'default_account_id' => $account->id,
    ]);

    $move = Move::factory()->create([
        'company_id' => $company->id,
        'journal_id' => $journal->id,
        'state'      => $state,
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
        'parent_state' => $state,
        'date'         => $date,
    ]);
}

function makeEngine(): ReportCalculationEngine
{
    return new ReportCalculationEngine(
        new \Webkul\Accounting\Repositories\LedgerBalanceRepository(),
        new AccountBindingService(includeDescendants: false),
        new FormulaEvaluator(),
    );
}

it('computes detail lines and a subtotal from posted ledger data', function () {
    $company = Company::factory()->create();

    $revenue = Account::factory()->create();
    $other   = Account::factory()->create();

    // Revenue 1000, Other income 250, within Jan 2025.
    postLine($company, $revenue, 1000.0, '2025-01-10');
    postLine($company, $other, 250.0, '2025-01-20');

    $template = ReportTemplate::factory()->create();

    $revLine = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail',
        'caption' => 'Revenue', 'sort' => 1,
    ]);
    $otherLine = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail',
        'caption' => 'Other Income', 'sort' => 2,
    ]);
    $total = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'subtotal',
        'caption' => 'Total Income', 'sort' => 3,
    ]);

    ReportLineAccount::factory()->create(['report_line_id' => $revLine->id, 'account_id' => $revenue->id, 'sign' => 1]);
    ReportLineAccount::factory()->create(['report_line_id' => $otherLine->id, 'account_id' => $other->id, 'sign' => 1]);

    // Total = Revenue + Other Income
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $revLine->id, 'operator' => '+', 'sort' => 0]);
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $otherLine->id, 'operator' => '+', 'sort' => 1]);

    $period  = ReportPeriod::make('2025-01', 'Jan', \Carbon\Carbon::create(2025, 1, 1), \Carbon\Carbon::create(2025, 1, 31));
    $context = ReportContext::forCompany($company->id);

    $result = makeEngine()->calculate($template, [$period], $context);

    $byId = $result->keyBy('lineId');

    expect($byId[$revLine->id]->valueFor('2025-01'))->toBe(1000.0)
        ->and($byId[$otherLine->id]->valueFor('2025-01'))->toBe(250.0)
        ->and($byId[$total->id]->valueFor('2025-01'))->toBe(1250.0);
});

it('excludes draft moves and respects the date window', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    // In-window posted (counts), in-window draft (excluded), out-of-window posted (excluded).
    postLine($company, $account, 500.0, '2025-03-10');

    postLine($company, $account, 999.0, '2025-03-11', MoveState::DRAFT);

    postLine($company, $account, 777.0, '2025-05-01');

    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail', 'sort' => 1]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $period  = ReportPeriod::make('2025-03', 'Mar', \Carbon\Carbon::create(2025, 3, 1), \Carbon\Carbon::create(2025, 3, 31));
    $context = ReportContext::forCompany($company->id);

    $result = makeEngine()->calculate($template, [$period], $context)->keyBy('lineId');

    expect($result[$line->id]->valueFor('2025-03'))->toBe(500.0);
});

it('scopes by company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $account  = Account::factory()->create();

    postLine($companyA, $account, 300.0, '2025-02-10');
    postLine($companyB, $account, 900.0, '2025-02-10');

    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail', 'sort' => 1]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $period = ReportPeriod::make('2025-02', 'Feb', \Carbon\Carbon::create(2025, 2, 1), \Carbon\Carbon::create(2025, 2, 28));

    $resultA = makeEngine()->calculate($template, [$period], ReportContext::forCompany($companyA->id))->keyBy('lineId');
    $resultBoth = makeEngine()->calculate($template, [$period], ReportContext::forCompanies([$companyA->id, $companyB->id]))->keyBy('lineId');

    expect($resultA[$line->id]->valueFor('2025-02'))->toBe(300.0)
        ->and($resultBoth[$line->id]->valueFor('2025-02'))->toBe(1200.0);
});

it('produces a value per period for a monthly matrix', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    postLine($company, $account, 100.0, '2025-01-15');
    postLine($company, $account, 200.0, '2025-02-15');

    $template = ReportTemplate::factory()->monthlyMatrix()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail', 'sort' => 1]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $jan = ReportPeriod::make('2025-01', 'Jan', \Carbon\Carbon::create(2025, 1, 1), \Carbon\Carbon::create(2025, 1, 31));
    $feb = ReportPeriod::make('2025-02', 'Feb', \Carbon\Carbon::create(2025, 2, 1), \Carbon\Carbon::create(2025, 2, 28));

    $result = makeEngine()->calculate($template, [$jan, $feb], ReportContext::forCompany($company->id))->keyBy('lineId');

    expect($result[$line->id]->valueFor('2025-01'))->toBe(100.0)
        ->and($result[$line->id]->valueFor('2025-02'))->toBe(200.0);
});
