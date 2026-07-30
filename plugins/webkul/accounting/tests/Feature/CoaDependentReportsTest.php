<?php

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\BalanceSheet;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\TrialBalanceExport;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\GeneralLedger;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ProfitLoss;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\TrialBalance;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function depRows(): array
{
    $reader = new CoaSheetReader;
    $rows = $reader->read(base_path('Chart_of_Accounts_Trial_Balance_Test.csv'));

    return (new CoaSheetParser)->parse($rows, (new CoaHeaderDetector)->detect($rows));
}

function depImport(): Company
{
    $company = Company::factory()->create([
        'name'        => 'Dependent Reports Co',
        'currency_id' => Currency::query()->orderBy('id')->value('id'),
    ]);
    Journal::factory()->create([
        'company_id'  => $company->id,
        'currency_id' => $company->currency_id,
        'type'        => JournalType::GENERAL,
    ]);

    app(CoaImportService::class)->import(
        rows: depRows(), company: $company, mode: 'with_journals',
        currencyId: $company->currency_id,
        openingDate: '2025-07-01', movementDate: '2025-07-30', adjustmentDate: '2025-07-31',
    );

    return $company;
}

/**
 * Net (debit - credit) of posted lines for the company, grouped by the
 * AccountType category — the same source Balance Sheet and P&L read.
 *
 * @return array<string, float>
 */
function depCategoryNets(int $companyId): array
{
    $rows = DB::table('accounts_account_move_lines as l')
        ->join('accounts_account_moves as m', 'm.id', '=', 'l.move_id')
        ->join('accounts_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('m.company_id', $companyId)
        ->where('m.state', 'posted')
        ->groupBy('a.account_type')
        ->selectRaw('a.account_type, SUM(l.debit - l.credit) as net')
        ->pluck('net', 'account_type');

    $bucket = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0, 'income' => 0.0, 'expense' => 0.0];

    foreach ($rows as $type => $net) {
        $prefix = explode('_', (string) $type)[0];
        $bucket[$prefix] = ($bucket[$prefix] ?? 0.0) + (float) $net;
    }

    return $bucket;
}

it('keeps the ledger balanced so the Balance Sheet reconciles (Assets = Liabilities + Equity + P&L)', function () {
    $company = depImport();

    $net = depCategoryNets($company->id);

    // Every posted line nets to zero -> the balance sheet balances.
    $total = array_sum($net);
    expect(round($total, 2))->toBe(0.0);

    // Assets = -(Liabilities + Equity) - (Income + Expense nets), i.e. the
    // accounting equation with current-period P&L included.
    $assets = $net['asset'];
    $liabEquity = $net['liability'] + $net['equity'];
    $profitAndLoss = $net['income'] + $net['expense'];

    expect(round($assets + $liabEquity + $profitAndLoss, 2))->toBe(0.0);
});

it('reconciles the General Ledger with the Trial Balance per account', function () {
    $company = depImport();

    $tb = app(TrialBalanceService::class)->compute($company->id, '2025-07-02', '2025-07-31');
    $byCode = collect($tb['rows'])->keyBy('code');

    // For a few known accounts, the GL net (all posted lines) equals the TB
    // closing net.
    foreach (['1028' => 580000.0, '1040' => -700000.0, '1018' => 300000.0] as $code => $expectedNet) {
        $accountId = Account::query()->where('code', $code)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->value('id');

        $glNet = (float) DB::table('accounts_account_move_lines as l')
            ->join('accounts_account_moves as m', 'm.id', '=', 'l.move_id')
            ->where('m.company_id', $company->id)->where('m.state', 'posted')
            ->where('l.account_id', $accountId)
            ->sum(DB::raw('l.debit - l.credit'));

        $tbNet = $byCode[$code]['closing_debit'] - $byCode[$code]['closing_credit'];

        expect(round($glNet, 2))->toBe(round($expectedNet, 2))
            ->and(round($tbNet, 2))->toBe(round($glNet, 2));
    }
});

it('renders the statement report pages without 403/500 after import', function () {
    $company = depImport();

    $user = User::factory()->create(['is_active' => true, 'default_company_id' => $company->id]);
    foreach ([
        'page_accounting_trial_balance', 'page_accounting_balance_sheet',
        'page_accounting_profit_loss', 'page_accounting_general_ledger',
    ] as $p) {
        Permission::findOrCreate($p, 'web');
        $user->givePermissionTo($p);
    }
    test()->actingAs($user);

    foreach ([TrialBalance::getUrl(), BalanceSheet::getUrl(), ProfitLoss::getUrl(), GeneralLedger::getUrl()] as $url) {
        test()->get($url)->assertOk();
    }
});

it('produces identical Trial Balance totals on screen data and Excel export', function () {
    $company = depImport();

    $tb = app(TrialBalanceService::class)->compute($company->id, '2025-07-02', '2025-07-31');

    $export = new TrialBalanceExport(
        $tb['rows'], $tb['totals'], $company->name, '2025-07-02', '2025-07-31',
    );
    $grid = $export->array();

    // The Total row is second from the bottom; closing debit/credit are the
    // last two columns.
    $totalRow = $grid[count($grid) - 2];
    expect((float) $totalRow[9])->toBe($tb['totals']['closing_debit'])
        ->and((float) $totalRow[10])->toBe($tb['totals']['closing_credit'])
        ->and($tb['totals']['closing_debit'])->toBe(1170000.0);
});
