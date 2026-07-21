<?php

use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportQueryService;
use Webkul\Support\Models\Company;

function queryService(): ReportQueryService
{
    return app(ReportQueryService::class);
}

it('serves cached results and invalidates them when a manual input is saved', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->monthlyMatrix()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'value_source' => ValueSource::MANUAL,
    ]);

    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-01-15', 'value' => 10]);

    $context = ReportContext::forCompany($company->id);
    $service = queryService();

    $first = $service->getReport($template, 2025, $context)->keyBy('lineId');
    expect($first[$line->id]->valueFor('2025-01'))->toBe(10.0);

    // Saving an input touches the line, which touches the template, which
    // rotates the cache key — so the next read recomputes. Timestamps have
    // one-second resolution, so move the clock to make the touch observable.
    test()->travelTo(now()->addMinute());

    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-01-20', 'value' => 5]);

    $second = $service->getReport($template->fresh(), 2025, $context)->keyBy('lineId');
    expect($second[$line->id]->valueFor('2025-01'))->toBe(15.0);
});

it('exposes resolved columns, including spacers, for renderers', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->create();

    ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => 6]);
    ReportColumn::factory()->spacer()->create(['report_template_id' => $template->id]);
    ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id]);

    $columns = queryService()->columnsFor($template, 2025, ReportContext::forCompany($company->id));

    expect($columns)->toHaveCount(3)
        ->and($columns[1]->isSpacer())->toBeTrue()
        ->and($columns[0]->isSpacer())->toBeFalse();
});
