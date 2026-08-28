<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Contracts\MeasureResolver;
use Webkul\Accounting\Data\MeasureReference;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Data\ResolutionContext;
use Webkul\Accounting\Data\ResolvedSeries;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Accounting\Services\AccountBindingService;
use Webkul\Accounting\Services\Formula\FormulaEvaluator;
use Webkul\Accounting\Services\MeasureResolverRegistry;
use Webkul\Accounting\Services\ReportCalculationEngine;
use Webkul\Accounting\Services\ReportValueProviderRegistry;
use Webkul\Accounting\Services\Resolvers\LedgerMeasureResolver;
use Webkul\Support\Models\Company;

function mrPost(Company $company, Account $account, float $balance, string $date): void
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

function mrMonth(int $year, int $month): ReportPeriod
{
    $start = Carbon::create($year, $month, 1)->startOfMonth();

    return ReportPeriod::make(sprintf('%04d-%02d', $year, $month), $start->format('M'), $start, $start->copy()->endOfMonth());
}

function mrEngine(?MeasureResolverRegistry $registry = null): ReportCalculationEngine
{
    return new ReportCalculationEngine(
        new LedgerBalanceRepository,
        new AccountBindingService(includeDescendants: false),
        new FormulaEvaluator,
        new ReportValueProviderRegistry,
        $registry,
    );
}

it('routes a ledger measure through the resolver and matches the repository exactly', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    mrPost($company, $account, 1000.0, '2025-01-10');
    mrPost($company, $account, 500.0, '2025-02-10');

    $periods = [mrMonth(2025, 1), mrMonth(2025, 2)];
    $context = ReportContext::forCompany($company->id);

    $resolver = new LedgerMeasureResolver(new LedgerBalanceRepository);

    $series = $resolver->resolve(
        [MeasureReference::ledgerAccount($account->id)],
        $periods,
        new ResolutionContext($context, ValueBasis::MOVEMENT),
    );

    // Identical to calling the repository directly.
    $direct = (new LedgerBalanceRepository)->basisBalances([$account->id], $periods, $context, ValueBasis::MOVEMENT);

    expect($series)->toBeInstanceOf(ResolvedSeries::class)
        ->and($series->valueFor($account->id, '2025-01'))->toBe(1000.0)
        ->and($series->valueFor($account->id, '2025-02'))->toBe(500.0)
        ->and($series->all())->toBe($direct);
});

it('registers the ledger resolver and returns it by source', function () {
    $registry = new MeasureResolverRegistry;
    $registry->register(new LedgerMeasureResolver(new LedgerBalanceRepository));

    expect($registry->has('ledger'))->toBeTrue()
        ->and($registry->sources())->toBe(['ledger'])
        ->and($registry->for('ledger'))->toBeInstanceOf(LedgerMeasureResolver::class)
        ->and($registry->for('ledger')->source())->toBe('ledger');
});

it('binds the ledger resolver on the container-resolved registry', function () {
    $registry = app(MeasureResolverRegistry::class);

    expect($registry->has('ledger'))->toBeTrue()
        ->and($registry->for('ledger'))->toBeInstanceOf(LedgerMeasureResolver::class);
});

it('fails clearly for an unknown measure source', function () {
    $registry = new MeasureResolverRegistry;
    $registry->register(new LedgerMeasureResolver(new LedgerBalanceRepository));

    expect(fn () => $registry->for('imported_dataset'))
        ->toThrow(RuntimeException::class, 'No measure resolver registered for source [imported_dataset]');
});

it('produces identical report results whether routed through a custom or default registry', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    mrPost($company, $account, 750.0, '2025-04-10');

    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $period = mrMonth(2025, 4);
    $context = ReportContext::forCompany($company->id);

    // Default (engine builds its own registry) vs an explicitly supplied registry.
    $viaDefault = mrEngine()->calculate($template, [$period], $context)->keyBy('lineId');

    $registry = new MeasureResolverRegistry;
    $registry->register(new LedgerMeasureResolver(new LedgerBalanceRepository));
    $viaCustom = mrEngine($registry)->calculate($template, [$period], $context)->keyBy('lineId');

    expect($viaDefault[$line->id]->valueFor('2025-04'))->toBe(750.0)
        ->and($viaCustom[$line->id]->valueFor('2025-04'))->toBe(750.0);
});

it('introduces no extra ledger queries through the seam', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    mrPost($company, $account, 900.0, '2025-06-10');

    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $period = mrMonth(2025, 6);
    $context = ReportContext::forCompany($company->id);

    $ledgerQueries = 0;
    DB::listen(function ($query) use (&$ledgerQueries) {
        if (str_contains($query->sql, 'accounts_account_move_lines')) {
            $ledgerQueries++;
        }
    });

    $result = mrEngine()->calculate($template, [$period], $context)->keyBy('lineId');

    // One (scope, basis) group, movement basis -> exactly one ledger query
    // (dailyBalances); the resolver seam adds none.
    expect($result[$line->id]->valueFor('2025-06'))->toBe(900.0)
        ->and($ledgerQueries)->toBe(1);
});

it('exposes a resolver whose source contract is stable', function () {
    $resolver = new LedgerMeasureResolver(new LedgerBalanceRepository);

    expect($resolver)->toBeInstanceOf(MeasureResolver::class)
        ->and($resolver->source())->toBe(MeasureReference::SOURCE_LEDGER);
});
