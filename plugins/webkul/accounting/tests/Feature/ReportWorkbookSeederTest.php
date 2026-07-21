<?php

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportQueryService;
use Webkul\Support\Models\Company;

function wbSeed(): void
{
    // The dev database may hold user-edited copies of the workbook templates;
    // purge them (inside this test's transaction) so the seeder always imports
    // a pristine set for the assertions below.
    DB::table('accounting_report_templates')
        ->whereIn('code', ['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'])
        ->delete();

    test()->seed(ReportWorkbookSeeder::class);
}

function wbTemplate(string $code): ReportTemplate
{
    return ReportTemplate::query()->where('code', $code)->firstOrFail();
}

function wbPost(Company $company, Account $account, float $balance, string $date): void
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

it('imports all six worksheets as templates, idempotently', function () {
    wbSeed();
    wbSeed();

    $codes = ReportTemplate::query()->pluck('code');

    foreach (['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'] as $code) {
        expect($codes)->toContain($code);
    }

    expect(ReportTemplate::query()->whereIn('code', ['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'])->count())->toBe(6);
});

it('preserves the TIN PNL row order, captions and blank rows verbatim', function () {
    wbSeed();

    $captions = wbTemplate('tin-pnl')->lines()->get()
        ->map(fn ($line) => $line->line_type === LineType::SPACER ? '<blank>' : $line->caption)
        ->all();

    expect($captions)->toBe([
        'GMV', 'GST', "Trucker's Commission", 'GM',
        'Fleet Subsidy', 'Customer Subsidy', 'NR',
        'Offline Mktg & Channels', 'Digital Mktg.', 'CM1',
        'Financial Charges', 'Tech', 'Call Center & Support', 'Returns & Waivers', 'CM 2',
        'People', 'Real Estate', 'Travel & Entertainment', 'Professional Services', 'Misc.', 'EBITDA',
        'Depreciation', 'Ammortization', 'EBIT',
        'Interest', 'EBTDA',
        'Income Tax', 'Cost of Compliance', 'TIN NI',
        '<blank>',
        'OpenPort NI', 'Rider NI', 'Total NI',
        '<blank>',
        'USD Rate',
    ]);
});

it('builds the BS Group entity-matrix columns with a spacer between the halves', function () {
    wbSeed();

    $columns = wbTemplate('bs-group')->columns()->get();

    expect($columns)->toHaveCount(9)
        ->and($columns->pluck('label')->all())->toBe([
            'TIN - Final', 'Rider', 'OP', 'Consolidated',
            null,
            'TIN - Final', 'Rider', 'OP', 'Consolidated',
        ])
        ->and($columns[4]->column_type)->toBe(ColumnType::SPACER)
        ->and($columns[3]->is_consolidated)->toBeTrue()
        ->and($columns[0]->start_month)->toBe(6)
        ->and($columns[5]->start_month)->toBe(12);
});

it('marks the BS Check row and wires Total Assets minus Total Equity and Liabilities', function () {
    wbSeed();

    $check = wbTemplate('bs-group')->lines()->where('caption', 'Check')->first();

    expect($check->is_check)->toBeTrue()
        ->and($check->formulas)->toHaveCount(2);
});

it('gives cashflow lines their bases and half-year range columns', function () {
    wbSeed();

    $template = wbTemplate('cashflow-group');
    $beginning = $template->lines()->where('caption', 'Beginning cash & cash equivalents')->first();
    $columns = $template->columns()->get();

    expect($beginning->value_basis)->toBe(ValueBasis::OPENING_BALANCE)
        ->and($columns->firstWhere('label', 'Rider/TPL USD')->start_month)->toBe(1)
        ->and($columns->firstWhere('label', 'Rider/TPL USD')->end_month)->toBe(6)
        ->and($columns->firstWhere('label', 'Rider USD')->start_month)->toBe(7)
        ->and($columns->firstWhere('label', 'Rider USD')->end_month)->toBe(12);
});

it('leaves every imported ledger line unmapped and marks KPI rows manual', function () {
    wbSeed();

    $ledgerLines = wbTemplate('tin-pnl')->lines()->get()
        ->filter(fn ($line) => $line->effectiveValueSource() === ValueSource::LEDGER);

    expect($ledgerLines->isNotEmpty())->toBeTrue();

    foreach ($ledgerLines as $line) {
        expect($line->accountBindings()->count())->toBe(0);
    }

    $parcels = wbTemplate('ridershipline-pnl')->lines()->where('caption', 'No. of Parcels')->first();

    expect($parcels->effectiveValueSource())->toBe(ValueSource::MANUAL);
});

it('computes a seeded BS Group end-to-end once accounts are bound: Check nets to zero', function () {
    $company = Company::factory()->create(['name' => 'Truck It In (Test)']);

    wbSeed();

    $asset = Account::factory()->create();
    $equity = Account::factory()->create();

    // A balanced ledger: 500 debit into the asset, 500 credit into equity.
    wbPost($company, $asset, 500.0, '2025-03-10');
    wbPost($company, $equity, -500.0, '2025-03-10');

    $template = wbTemplate('bs-group');

    $tradeDebts = $template->lines()->where('caption', 'Trade Debts')->first();
    $capital = $template->lines()->where('caption', 'Issued, subscribed and paid-up capital')->first();

    ReportLineAccount::query()->create(['report_line_id' => $tradeDebts->id, 'account_id' => $asset->id, 'sign' => 1]);
    ReportLineAccount::query()->create(['report_line_id' => $capital->id, 'account_id' => $equity->id, 'sign' => -1]);

    $context = ReportContext::forCompany($company->id);
    $service = app(ReportQueryService::class);

    $rows = $service->getReport($template, 2025, $context, useCache: false)->keyBy('lineId');
    $columns = $service->columnsFor($template, 2025, $context);

    $junTin = $columns[0]->key;

    $totalAssets = $template->lines()->where('caption', 'Total Assets')->first();
    $totalEquity = $template->lines()->where('caption', 'Total Equity and Liabilities')->first();
    $check = $template->lines()->where('caption', 'Check')->first();

    expect($rows[$totalAssets->id]->valueFor($junTin))->toBe(500.0)
        ->and($rows[$totalEquity->id]->valueFor($junTin))->toBe(500.0)
        ->and($rows[$check->id]->valueFor($junTin))->toBe(0.0)
        ->and($rows[$check->id]->isCheck)->toBeTrue();
});
