<?php

use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Data\Coa\CoaWarning;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaRowValidator;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function coaFile(): string
{
    return base_path('Chart_of_Accounts_Trial_Balance_Test.csv');
}

function coaCompany(string $name = 'CoA Test Co'): Company
{
    $company = Company::factory()->create([
        'name'        => $name,
        'currency_id' => Currency::query()->orderBy('id')->value('id'),
    ]);
    Journal::factory()->create([
        'company_id'  => $company->id,
        'currency_id' => $company->currency_id,
        'type'        => JournalType::GENERAL,
    ]);

    return $company;
}

/**
 * @return array<int, CoaRow>
 */
function coaRows(): array
{
    $reader = new CoaSheetReader;
    $rows = $reader->read(coaFile());
    $map = (new CoaHeaderDetector)->detect($rows);

    expect($map->headerRowIndex)->toBe(4); // CSV row 5 (0-indexed 4)

    return (new CoaSheetParser)->parse($rows, $map);
}

it('detects the header on the correct row and parses exactly 62 leaf accounts', function () {
    $rows = coaRows();

    expect($rows)->toHaveCount(62)
        ->and($rows[0]->code)->toBe('1011')
        ->and($rows[0]->title)->toBe('Furniture and fixture')
        ->and($rows[61]->code)->toBe('New code 3');
});

it('flags the known data-quality issues without changing them', function () {
    $warnings = (new CoaRowValidator)->validate(coaRows());
    $codes = collect($warnings)->map(fn (CoaWarning $w) => $w->code)->unique()->values();

    foreach ([
        'non_numeric_code',        // New code 1/2/3
        'possible_misspelling',    // Libailities, Intanbgible, Guarrantee
        'duplicate_title',         // Allowance for doubtful debts, Bank Charges
        'nature_section_mismatch', // P&L / Assets / A Rec written off
        'input_tax_under_liability',
        'provision_under_asset',
        'cost_as_revenue',         // Trucker's costs under Revenue
    ] as $expected) {
        expect($codes)->toContain($expected);
    }

    // Flags are warnings, not errors — the import is allowed after acknowledgement.
    expect(collect($warnings)->contains(fn (CoaWarning $w) => $w->isError()))->toBeFalse();
});

it('imports the hierarchy + balanced migration journals and produces the exact Trial Balance', function () {
    $company = coaCompany();
    $rows = coaRows();

    $batch = app(CoaImportService::class)->import(
        rows: $rows,
        company: $company,
        mode: 'with_journals',
        currencyId: $company->currency_id,
        filename: 'Chart_of_Accounts_Trial_Balance_Test.csv',
        openingDate: '2025-07-01',
        movementDate: '2025-07-30',
        adjustmentDate: '2025-07-31',
    );

    expect($batch->accounts_created)->toBe(62)
        ->and($batch->groups_created)->toBeGreaterThan(0)
        ->and($batch->journals_created)->toBe(3);

    // Group accounts are non-postable and excluded from the postable scope.
    $groups = Account::query()->where('is_group', true)
        ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->count();
    expect($groups)->toBe($batch->groups_created)
        ->and(Account::query()->postable()->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->where('code', '1011')->exists())->toBeTrue();

    // Official Trial Balance from posted ledger lines (period after the opening date).
    $tb = app(TrialBalanceService::class)->compute($company->id, '2025-07-02', '2025-07-31');
    $t = $tb['totals'];

    expect($t['opening_debit'])->toBe(800000.0)
        ->and($t['opening_credit'])->toBe(800000.0)
        ->and($t['movement_debit'])->toBe(685000.0)
        ->and($t['movement_credit'])->toBe(685000.0)
        ->and($t['adjustment_debit'])->toBe(20000.0)
        ->and($t['adjustment_credit'])->toBe(20000.0)
        ->and($t['closing_debit'])->toBe(1170000.0)
        ->and($t['closing_credit'])->toBe(1170000.0)
        ->and($t['difference'])->toBe(0.0);

    // Exact non-zero closing balances by code.
    $byCode = collect($tb['rows'])->keyBy('code');
    $expected = [
        '1011' => ['closing_debit', 100000.0],
        '1017' => ['closing_credit', 30000.0],
        '1018' => ['closing_debit', 300000.0],
        '1028' => ['closing_debit', 580000.0],
        '1035' => ['closing_credit', 88000.0],
        '1040' => ['closing_credit', 700000.0],
        '1042' => ['closing_credit', 25000.0],
        '1047' => ['closing_credit', 12000.0],
        '1048' => ['closing_debit', 12000.0],
        '1050' => ['closing_credit', 300000.0],
        '1056' => ['closing_debit', 90000.0],
        '1057' => ['closing_debit', 5000.0],
        '1058' => ['closing_debit', 48000.0],
        '1063' => ['closing_debit', 25000.0],
        '1064' => ['closing_debit', 10000.0],
        '1065' => ['closing_credit', 15000.0],
    ];
    foreach ($expected as $code => [$col, $value]) {
        expect($byCode[$code][$col])->toBe($value, "account {$code} {$col}");
    }
});

it('is idempotent: a second import creates no duplicate accounts or journals', function () {
    $company = coaCompany();
    $rows = coaRows();

    $service = app(CoaImportService::class);
    $args = [
        'rows'        => $rows, 'company' => $company, 'mode' => 'with_journals',
        'currencyId'  => $company->currency_id,
        'openingDate' => '2025-07-01', 'movementDate' => '2025-07-30', 'adjustmentDate' => '2025-07-31',
    ];

    $service->import(...$args);
    $accountsAfterFirst = Account::query()->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->count();
    $movesAfterFirst = DB::table('accounts_account_moves')->where('company_id', $company->id)->count();

    $second = $service->import(...$args);

    expect($second->accounts_created)->toBe(0)
        ->and($second->journals_created)->toBe(0)
        ->and(Account::query()->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->count())->toBe($accountsAfterFirst)
        ->and(DB::table('accounts_account_moves')->where('company_id', $company->id)->count())->toBe($movesAfterFirst);
});

it('enforces company isolation: another company sees none of the imported data', function () {
    $companyA = coaCompany('Company A');
    $companyB = coaCompany('Company B');

    app(CoaImportService::class)->import(
        rows: coaRows(), company: $companyA, mode: 'with_journals',
        currencyId: $companyA->currency_id,
        openingDate: '2025-07-01', movementDate: '2025-07-30', adjustmentDate: '2025-07-31',
    );

    expect(Account::query()->whereHas('companies', fn ($q) => $q->where('companies.id', $companyB->id))->where('code', '1011')->exists())->toBeFalse();

    $tbB = app(TrialBalanceService::class)->compute($companyB->id, '2025-07-02', '2025-07-31');
    expect($tbB['totals']['closing_debit'])->toBe(0.0)
        ->and($tbB['totals']['closing_credit'])->toBe(0.0);
});

it('excludes draft (unposted) journals from the official Trial Balance', function () {
    $company = coaCompany();

    app(CoaImportService::class)->import(
        rows: coaRows(), company: $company, mode: 'with_journals',
        currencyId: $company->currency_id,
        openingDate: '2025-07-01', movementDate: '2025-07-30', adjustmentDate: '2025-07-31',
    );

    // Flip the movement journal to draft; posted-only TB must drop its 685k.
    DB::table('accounts_account_moves')
        ->where('company_id', $company->id)->where('coa_migration_kind', 'movement')
        ->update(['state' => 'draft']);

    $tb = app(TrialBalanceService::class)->compute($company->id, '2025-07-02', '2025-07-31', ['posted_only' => true]);

    expect($tb['totals']['movement_debit'])->toBe(0.0)
        ->and($tb['totals']['movement_credit'])->toBe(0.0);
});
