<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ExternalProviders;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\FinancialReports;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ReportMappingReview;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages\EditReportTemplate;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages\ListReportTemplates;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function reportsPurgeAndSeed(): void
{
    // Purge possibly user-edited dev copies (rolled back with the test) so the
    // seeder always provides the pristine workbook structure.
    DB::table('accounting_report_templates')
        ->whereIn('code', ['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'])
        ->delete();

    test()->seed(ReportWorkbookSeeder::class);
}

function reportsPageUser(): User
{
    $user = User::factory()->create(['is_active' => true]);

    foreach (['page_accounting_financial_reports', 'page_accounting_report_mapping_review', 'page_accounting_external_providers'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo([
        'page_accounting_financial_reports',
        'page_accounting_report_mapping_review',
        'page_accounting_external_providers',
    ]);

    return $user;
}

function reportsPagePost(Company $company, Account $account, float $balance, string $date): void
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

function reportsPreviewDir(): string
{
    $dir = storage_path('app/stage4-previews');
    File::ensureDirectoryExists($dir);

    return $dir;
}

it('renders every seeded workbook template through the Financial Reports page', function () {
    $company = Company::factory()->create(['name' => 'Truck It In (Test)']);

    reportsPurgeAndSeed();

    // Demo ledger + a few bindings so the captured previews show live values:
    // a balanced balance sheet (Check = 0) and TIN PNL revenue flow.
    $asset = Account::factory()->create();
    $equity = Account::factory()->create();
    $revenue = Account::factory()->create();

    reportsPagePost($company, $asset, 750000.0, '2025-05-15');
    reportsPagePost($company, $equity, -750000.0, '2025-05-15');
    reportsPagePost($company, $revenue, 125000.0, '2025-02-10');
    reportsPagePost($company, $revenue, 145000.0, '2025-03-10');

    $bs = ReportTemplate::query()->where('code', 'bs-group')->firstOrFail();
    ReportLineAccount::query()->create([
        'report_line_id' => $bs->lines()->where('caption', 'Trade Debts')->value('id'),
        'account_id'     => $asset->id,
        'sign'           => 1,
    ]);
    ReportLineAccount::query()->create([
        'report_line_id' => $bs->lines()->where('caption', 'Issued, subscribed and paid-up capital')->value('id'),
        'account_id'     => $equity->id,
        'sign'           => -1,
    ]);

    $tin = ReportTemplate::query()->where('code', 'tin-pnl')->firstOrFail();
    ReportLineAccount::query()->create([
        'report_line_id' => $tin->lines()->where('caption', 'GMV')->value('id'),
        'account_id'     => $revenue->id,
        'sign'           => 1,
    ]);
    ReportLineInput::query()->create([
        'report_line_id' => $tin->lines()->where('caption', 'USD Rate')->value('id'),
        'date'           => '2025-02-01',
        'value'          => 278.5,
    ]);

    $expectations = [
        'bs-group'          => ['ASSETS', 'Total Assets', 'TIN - Final', 'Consolidated', 'Check', '750,000'],
        'cashflow-group'    => ['Cash Flow Statement USD (Consolidated)', 'Cash from Operations', 'Ending cash &amp; cash equivalents', 'TIN Cons (USD)'],
        'ridershipline-pnl' => ['No. of Parcels', 'RPS', 'Total Direct Cost', 'Ebitda'],
        'op-pnl'            => ['Volume', 'Ebitda', 'USD Rate'],
        'tin-pnl'           => ['GMV', 'Trucker&#039;s Commission', 'CM 2', 'EBTDA', 'Total NI', '125,000'],
        'notes'             => ['Workings', 'Bank Reconciliations', 'MIS Reconciliation'],
    ];

    test()->actingAs(reportsPageUser());

    foreach ($expectations as $code => $needles) {
        $template = ReportTemplate::query()->where('code', $code)->firstOrFail();

        $response = test()->get(FinancialReports::getUrl(['template' => $template->id, 'year' => 2025]));

        $response->assertOk();

        $html = $response->getContent();

        foreach ($needles as $needle) {
            expect(str_contains($html, $needle))
                ->toBeTrue("Expected rendered '{$code}' to contain '{$needle}'.");
        }

        File::put(reportsPreviewDir()."/report-{$code}.html", $html);
    }
});

it('renders the mapping review and external providers pages', function () {
    reportsPurgeAndSeed();

    test()->actingAs(reportsPageUser());

    $review = test()->get(ReportMappingReview::getUrl());
    $review->assertOk();

    expect(str_contains($review->getContent(), 'Unmapped account lines'))->toBeTrue();

    File::put(reportsPreviewDir().'/mapping-review.html', $review->getContent());

    $providers = test()->get(ExternalProviders::getUrl());
    $providers->assertOk();

    File::put(reportsPreviewDir().'/external-providers.html', $providers->getContent());
});

it('renders the template administration list and designer pages', function () {
    reportsPurgeAndSeed();

    test()->actingAs(reportsPageUser());

    $list = test()->get(ListReportTemplates::getUrl());
    $list->assertOk();

    expect(str_contains($list->getContent(), 'BS Group'))->toBeTrue()
        ->and(str_contains($list->getContent(), 'Draft'))->toBeTrue();

    File::put(reportsPreviewDir().'/template-list.html', $list->getContent());

    $template = ReportTemplate::query()->where('code', 'bs-group')->firstOrFail();

    $edit = test()->get(EditReportTemplate::getUrl(['record' => $template]));
    $edit->assertOk();

    expect(str_contains($edit->getContent(), 'Publish'))->toBeTrue();

    File::put(reportsPreviewDir().'/template-edit.html', $edit->getContent());
});
