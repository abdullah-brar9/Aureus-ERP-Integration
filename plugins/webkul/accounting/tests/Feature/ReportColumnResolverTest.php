<?php

use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportColumnResolver;
use Webkul\Support\Models\Company;

function resolveColumns(ReportTemplate $template, int $year = 2025): array
{
    return (new ReportColumnResolver)->resolve($template, $year, ReportContext::forCompanies([]));
}

it('falls back to twelve months plus a total for a monthly matrix without column definitions', function () {
    $template = ReportTemplate::factory()->monthlyMatrix()->create();

    $columns = resolveColumns($template);

    expect($columns)->toHaveCount(13)
        ->and($columns[0]->key)->toBe('2025-01')
        ->and($columns[11]->key)->toBe('2025-12')
        ->and($columns[12]->key)->toBe('2025')
        ->and($columns[12]->period->startDate->toDateString())->toBe('2025-01-01')
        ->and($columns[12]->period->endDate->toDateString())->toBe('2025-12-31');
});

it('falls back to a single full-year column for a period_total layout', function () {
    $template = ReportTemplate::factory()->create();

    $columns = resolveColumns($template);

    expect($columns)->toHaveCount(1)
        ->and($columns[0]->key)->toBe('2025');
});

it('resolves explicit month, range, full-year and spacer columns', function () {
    $template = ReportTemplate::factory()->create();

    $month = ReportColumn::factory()->create([
        'report_template_id' => $template->id, 'start_month' => 6, 'label' => 'TIN - Final',
    ]);
    $spacer = ReportColumn::factory()->spacer()->create(['report_template_id' => $template->id]);
    $range = ReportColumn::factory()->range(1, 6)->create(['report_template_id' => $template->id]);
    $year = ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id]);

    $columns = collect(resolveColumns($template))->keyBy('key');

    $monthSpec = $columns->get('col_'.$month->id);
    expect($monthSpec->label)->toBe('TIN - Final')
        ->and($monthSpec->period->startDate->toDateString())->toBe('2025-06-01')
        ->and($monthSpec->period->endDate->toDateString())->toBe('2025-06-30');

    expect($columns->get('col_'.$spacer->id)->isSpacer())->toBeTrue();

    $rangeSpec = $columns->get('col_'.$range->id);
    expect($rangeSpec->period->startDate->toDateString())->toBe('2025-01-01')
        ->and($rangeSpec->period->endDate->toDateString())->toBe('2025-06-30')
        ->and($rangeSpec->label)->toBe('Jan-Jun');

    expect($columns->get('col_'.$year->id)->period->endDate->toDateString())->toBe('2025-12-31');
});

it('applies year offsets for comparative columns', function () {
    $template = ReportTemplate::factory()->create();

    $prior = ReportColumn::factory()->fullYear()->create([
        'report_template_id' => $template->id, 'year_offset' => -1,
    ]);

    $columns = collect(resolveColumns($template))->keyBy('key');

    expect($columns->get('col_'.$prior->id)->period->startDate->toDateString())->toBe('2024-01-01')
        ->and($columns->get('col_'.$prior->id)->period->endDate->toDateString())->toBe('2024-12-31');
});

it('carries entity scope and consolidation flags onto the specs', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->create();

    $entity = ReportColumn::factory()->create([
        'report_template_id' => $template->id, 'start_month' => 6, 'company_id' => $company->id,
    ]);
    $consolidated = ReportColumn::factory()->consolidated()->create([
        'report_template_id' => $template->id, 'start_month' => 6,
    ]);

    $columns = collect(resolveColumns($template))->keyBy('key');

    expect($columns->get('col_'.$entity->id)->companyIds)->toBe([$company->id])
        ->and($columns->get('col_'.$entity->id)->isConsolidated)->toBeFalse()
        ->and($columns->get('col_'.$consolidated->id)->companyIds)->toBeNull()
        ->and($columns->get('col_'.$consolidated->id)->isConsolidated)->toBeTrue();
});

it('rejects a column with an invalid month configuration', function () {
    $template = ReportTemplate::factory()->create();

    ReportColumn::factory()->create([
        'report_template_id' => $template->id, 'start_month' => null,
    ]);

    resolveColumns($template);
})->throws(RuntimeException::class);
