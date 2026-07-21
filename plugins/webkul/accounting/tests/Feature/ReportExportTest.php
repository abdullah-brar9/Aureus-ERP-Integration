<?php

use Illuminate\Support\Facades\DB;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\ReportSpreadsheetExport;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportQueryService;

function exportPurgeAndSeed(): void
{
    // Purge possibly user-edited dev copies (rolled back with the test) so the
    // seeder always provides the pristine workbook structure.
    DB::table('accounting_report_templates')
        ->whereIn('code', ['bs-group', 'cashflow-group', 'ridershipline-pnl', 'op-pnl', 'tin-pnl', 'notes'])
        ->delete();

    test()->seed(ReportWorkbookSeeder::class);
}

function exportReport(string $code): array
{
    $template = ReportTemplate::query()->where('code', $code)->firstOrFail();
    $context = ReportContext::forCompanies([]);
    $service = app(ReportQueryService::class);

    $columns = $service->columnsFor($template, 2025, $context);
    $rows = $service->getReport($template, 2025, $context, useCache: false);

    return [$template, $columns, $rows];
}

it('exports the BS Group grid with entity headers, spacer column and blank rows', function () {
    exportPurgeAndSeed();

    [$template, $columns, $rows] = exportReport('bs-group');

    $export = new ReportSpreadsheetExport($template, $columns, $rows, usd: false, year: 2025);

    $grid = $export->array();

    // Header row: template name + 4 entity labels, spacer, 4 entity labels.
    expect($grid[0])->toBe([
        'BS Group', 'TIN - Final', 'Rider', 'OP', 'Consolidated', '',
        'TIN - Final', 'Rider', 'OP', 'Consolidated',
    ])
        ->and($grid[1][1])->toBe("Jun'25")
        ->and($grid[1][6])->toBe("Dec'25");

    // Row 3 (index 2) is "ASSETS"; workbook blank rows survive as empty rows.
    expect($grid[2][0])->toBe('ASSETS')
        ->and(collect($grid)->filter(fn ($row) => $row === [''])->count())->toBeGreaterThanOrEqual(6);

    // The spacer column is narrow, the caption column wide.
    $widths = $export->columnWidths();

    expect($widths['A'])->toBe(32)
        ->and($widths['F'])->toBe(1.5);
});

it('renders the PDF view with header, rows and page footer', function () {
    exportPurgeAndSeed();

    [$template, $columns, $rows] = exportReport('tin-pnl');

    $html = view('accounting::filament.clusters.reporting.pages.pdfs.financial-report', [
        'template'     => $template,
        'year'         => 2025,
        'columns'      => $columns,
        'rows'         => $rows,
        'companyLabel' => 'All companies',
        'generatedAt'  => now(),
        'subLabel'     => fn ($column) => $column->label,
        'formatValue'  => fn ($value) => $value === null ? '-' : number_format($value),
    ])->render();

    expect($html)
        ->toContain('TIN PNL')
        ->toContain('GMV')
        ->toContain('Total NI')
        ->toContain('page-number')
        ->toContain('Generated');
});
