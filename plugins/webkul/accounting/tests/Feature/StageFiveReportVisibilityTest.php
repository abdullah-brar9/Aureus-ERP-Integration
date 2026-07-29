<?php

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ExternalProviders;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\FinancialReports;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ReportMappingReview;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages\ListReportTemplates;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Security\Models\User;

function stageFiveUser(): User
{
    $user = User::factory()->create(['is_active' => true]);

    foreach ([
        'page_accounting_financial_reports',
        'page_accounting_report_mapping_review',
        'page_accounting_external_providers',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo([
        'page_accounting_financial_reports',
        'page_accounting_report_mapping_review',
        'page_accounting_external_providers',
    ]);

    return $user;
}

function stageFiveSeed(): void
{
    DB::table('accounting_report_templates')
        ->whereIn('code', ['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'])
        ->delete();

    test()->seed(ReportWorkbookSeeder::class);
}

it('seeds exactly the six workbook templates idempotently', function () {
    stageFiveSeed();
    stageFiveSeed(); // second run must not duplicate

    $codes = ReportTemplate::query()->pluck('code');

    foreach (['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'] as $code) {
        expect($codes->filter(fn ($c) => $c === $code))->toHaveCount(1);
    }

    expect(ReportTemplate::query()->whereIn('code', ['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'])->count())
        ->toBe(6);
});

it('exposes the Stage 5 report pages without 403/500 for a permissioned user', function () {
    stageFiveSeed();
    test()->actingAs(stageFiveUser());

    foreach ([
        FinancialReports::getUrl(),
        ReportMappingReview::getUrl(),
        ExternalProviders::getUrl(),
        ListReportTemplates::getUrl(),
    ] as $url) {
        test()->get($url)->assertOk();
    }
});

it('has the Stage 5 page permissions registered', function () {
    foreach ([
        'page_accounting_financial_reports',
        'page_accounting_report_mapping_review',
        'page_accounting_external_providers',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('groups Financial Reports under Reports and admin pages under Report Administration', function () {
    expect(FinancialReports::getNavigationGroup())->toBe('Reports')
        ->and(ReportMappingReview::getNavigationGroup())->toBe('Report Administration')
        ->and(ExternalProviders::getNavigationGroup())->toBe('Report Administration');
});
