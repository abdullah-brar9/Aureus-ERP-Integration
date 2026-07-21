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
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Accounting\Services\AccountBindingService;
use Webkul\Accounting\Services\Formula\FormulaEvaluator;
use Webkul\Accounting\Services\ReportCalculationEngine;
use Webkul\Accounting\Services\ReportColumnResolver;
use Webkul\Accounting\Services\ReportValueProviderRegistry;
use Webkul\Support\Models\Company;

function columnsSeed(Company $company, Account $account, float $balance, string $date): void
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

function columnsEngine(?ReportValueProviderRegistry $registry = null): ReportCalculationEngine
{
    return new ReportCalculationEngine(
        new LedgerBalanceRepository,
        new AccountBindingService(includeDescendants: false),
        new FormulaEvaluator,
        $registry ?? new ReportValueProviderRegistry,
    );
}

function columnsRun(ReportTemplate $template, ReportContext $context, ValueBasis $basis, ?ReportValueProviderRegistry $registry = null, int $year = 2025)
{
    $columns = (new ReportColumnResolver)->resolve($template, $year, $context);

    return columnsEngine($registry)
        ->calculateForColumns($template, $columns, $context, $basis)
        ->keyBy('lineId');
}

it('computes an entity-per-column matrix with a consolidated column', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $account = Account::factory()->create();

    columnsSeed($companyA, $account, 100.0, '2025-06-10');
    columnsSeed($companyB, $account, 200.0, '2025-06-10');

    $template = ReportTemplate::factory()->create();

    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $colA = ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => 6, 'company_id' => $companyA->id]);
    $colB = ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => 6, 'company_id' => $companyB->id]);
    $colC = ReportColumn::factory()->consolidated()->create(['report_template_id' => $template->id, 'start_month' => 6]);

    $context = ReportContext::forCompanies([$companyA->id, $companyB->id]);
    $result = columnsRun($template, $context, ValueBasis::MOVEMENT);

    expect($result[$line->id]->valueFor('col_'.$colA->id))->toBe(100.0)
        ->and($result[$line->id]->valueFor('col_'.$colB->id))->toBe(200.0)
        ->and($result[$line->id]->valueFor('col_'.$colC->id))->toBe(300.0);
});

it('uses a consolidation formula in consolidated columns and the value formula elsewhere', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $account = Account::factory()->create();

    columnsSeed($companyA, $account, 100.0, '2025-06-10');
    columnsSeed($companyB, $account, 200.0, '2025-06-10');

    $template = ReportTemplate::factory()->create();

    $detail = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);
    ReportLineAccount::factory()->create(['report_line_id' => $detail->id, 'account_id' => $account->id, 'sign' => 1]);

    $total = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);

    // Value formula: total = detail. Consolidation override: total = detail - 50
    // (an elimination adjustment applied only when consolidating).
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $detail->id, 'operator' => '+', 'sort' => 0]);
    ReportLineFormula::factory()->consolidation()->create(['report_line_id' => $total->id, 'operand_line_id' => $detail->id, 'operator' => '+', 'sort' => 0]);
    ReportLineFormula::factory()->consolidation()->constant(50.0)->create(['report_line_id' => $total->id, 'operator' => '-', 'sort' => 1]);

    $colA = ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => 6, 'company_id' => $companyA->id]);
    $colC = ReportColumn::factory()->consolidated()->create(['report_template_id' => $template->id, 'start_month' => 6]);

    $context = ReportContext::forCompanies([$companyA->id, $companyB->id]);
    $result = columnsRun($template, $context, ValueBasis::MOVEMENT);

    expect($result[$total->id]->valueFor('col_'.$colA->id))->toBe(100.0)
        ->and($result[$total->id]->valueFor('col_'.$colC->id))->toBe(250.0);
});

it('honours per-line value bases within one report', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    columnsSeed($company, $account, 1000.0, '2024-12-20');
    columnsSeed($company, $account, 100.0, '2025-03-10');

    $template = ReportTemplate::factory()->create();

    $opening = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'value_basis' => ValueBasis::OPENING_BALANCE,
    ]);
    $movement = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'value_basis' => ValueBasis::MOVEMENT,
    ]);
    $closing = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'value_basis' => ValueBasis::CLOSING_BALANCE,
    ]);

    foreach ([$opening, $movement, $closing] as $line) {
        ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);
    }

    $column = ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id]);

    $result = columnsRun($template, ReportContext::forCompany($company->id), ValueBasis::CLOSING_BALANCE);

    $key = 'col_'.$column->id;

    expect($result[$opening->id]->valueFor($key))->toBe(1000.0)
        ->and($result[$movement->id]->valueFor($key))->toBe(100.0)
        ->and($result[$closing->id]->valueFor($key))->toBe(1100.0);
});

it('sums manual input values into their periods and scopes them by company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $template = ReportTemplate::factory()->monthlyMatrix()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'value_source' => ValueSource::MANUAL,
    ]);

    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-01-15', 'value' => 10, 'company_id' => $companyA->id]);
    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-02-15', 'value' => 20, 'company_id' => $companyA->id]);
    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-02-20', 'value' => 999, 'company_id' => $companyB->id]);

    $result = columnsRun($template, ReportContext::forCompany($companyA->id), ValueBasis::MOVEMENT);

    expect($result[$line->id]->valueFor('2025-01'))->toBe(10.0)
        ->and($result[$line->id]->valueFor('2025-02'))->toBe(20.0)
        ->and($result[$line->id]->valueFor('2025'))->toBe(30.0);
});

it('pulls external line values from a registered provider', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->monthlyMatrix()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id,
        'line_type'          => 'detail',
        'value_source'       => ValueSource::EXTERNAL,
        'external_provider'  => 'test_kpi',
    ]);

    $registry = new ReportValueProviderRegistry;
    $registry->register('test_kpi', fn ($l, $period, $context) => (float) $period->startDate->month);

    $result = columnsRun($template, ReportContext::forCompany($company->id), ValueBasis::MOVEMENT, $registry);

    expect($result[$line->id]->valueFor('2025-01'))->toBe(1.0)
        ->and($result[$line->id]->valueFor('2025-07'))->toBe(7.0);
});

it('fails loudly when an external provider is not registered', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->create();

    ReportLine::factory()->create([
        'report_template_id' => $template->id,
        'line_type'          => 'detail',
        'value_source'       => ValueSource::EXTERNAL,
        'external_provider'  => 'nope',
    ]);

    columnsRun($template, ReportContext::forCompany($company->id), ValueBasis::MOVEMENT);
})->throws(RuntimeException::class);

it('lets a line-level company override win over the column scope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $account = Account::factory()->create();

    columnsSeed($companyA, $account, 100.0, '2025-06-10');
    columnsSeed($companyB, $account, 200.0, '2025-06-10');

    $template = ReportTemplate::factory()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'company_id' => $companyB->id,
    ]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $colA = ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => 6, 'company_id' => $companyA->id]);

    $result = columnsRun($template, ReportContext::forCompanies([$companyA->id, $companyB->id]), ValueBasis::MOVEMENT);

    expect($result[$line->id]->valueFor('col_'.$colA->id))->toBe(200.0);
});

it('keeps the legacy period-based calculate() path working unchanged', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    columnsSeed($company, $account, 500.0, '2025-04-10');

    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $period = ReportPeriod::make(
        '2025-04', 'Apr',
        Carbon::create(2025, 4, 1),
        Carbon::create(2025, 4, 30),
    );

    $result = columnsEngine()
        ->calculate($template, [$period], ReportContext::forCompany($company->id))
        ->keyBy('lineId');

    expect($result[$line->id]->valueFor('2025-04'))->toBe(500.0);
});
