<?php

use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportTemplateVersioningService;

function lifecycleService(): ReportTemplateVersioningService
{
    return app(ReportTemplateVersioningService::class);
}

/**
 * A minimal valid template: one mapped detail line + one subtotal.
 *
 * @return array{0: ReportTemplate, 1: ReportLine, 2: ReportLine}
 */
function lifecycleTemplate(): array
{
    $account = Account::factory()->create();
    $template = ReportTemplate::factory()->create();

    $detail = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'caption' => 'Revenue',
    ]);
    ReportLineAccount::factory()->create(['report_line_id' => $detail->id, 'account_id' => $account->id]);

    $total = ReportLine::factory()->subtotal()->create([
        'report_template_id' => $template->id, 'caption' => 'Total',
    ]);
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $detail->id]);

    return [$template->fresh(), $detail, $total];
}

it('refuses to publish a template with validation errors', function () {
    $template = ReportTemplate::factory()->create();
    ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);

    $errors = lifecycleService()->publish($template->fresh());

    expect($errors->isNotEmpty())->toBeTrue()
        ->and($template->fresh()->statusEnum())->toBe(TemplateStatus::DRAFT)
        ->and($template->fresh()->published_at)->toBeNull();
});

it('publishes a valid draft and stamps published_at', function () {
    [$template] = lifecycleTemplate();

    $errors = lifecycleService()->publish($template);

    expect($errors)->toBeEmpty()
        ->and($template->fresh()->statusEnum())->toBe(TemplateStatus::PUBLISHED)
        ->and($template->fresh()->published_at)->not->toBeNull();
});

it('makes published templates and their structure immutable', function () {
    [$template, $detail] = lifecycleTemplate();

    lifecycleService()->publish($template);
    $template = $template->fresh();

    expect(fn () => $template->update(['name' => 'Renamed']))->toThrow(RuntimeException::class)
        ->and(fn () => $detail->fresh()->update(['caption' => 'Changed']))->toThrow(RuntimeException::class)
        ->and(fn () => $detail->fresh()->accountBindings()->first()->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $template->delete())->toThrow(RuntimeException::class);
});

it('keeps manual values editable after publishing', function () {
    [$template, $detail] = lifecycleTemplate();

    lifecycleService()->publish($template);

    $input = ReportLineInput::query()->create([
        'report_line_id' => $detail->id, 'date' => '2025-01-15', 'value' => 42,
    ]);

    $input->update(['value' => 43]);

    expect((float) $input->fresh()->value)->toBe(43.0);
});

it('archives a published template', function () {
    [$template] = lifecycleTemplate();

    lifecycleService()->publish($template);
    lifecycleService()->archive($template->fresh());

    expect($template->fresh()->statusEnum())->toBe(TemplateStatus::ARCHIVED);
});

it('duplicates a published template into a remapped draft version', function () {
    [$template, $detail, $total] = lifecycleTemplate();

    ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id, 'label' => 'Year']);
    ReportLineInput::query()->create(['report_line_id' => $detail->id, 'date' => '2025-02-01', 'value' => 7]);
    $detail->update(['parent_id' => $total->id]);

    lifecycleService()->publish($template->fresh());

    $draft = lifecycleService()->newDraftVersion($template->fresh());

    expect($draft->statusEnum())->toBe(TemplateStatus::DRAFT)
        ->and($draft->version)->toBe($template->version + 1)
        ->and($draft->parent_template_id)->toBe($template->id)
        ->and($draft->published_at)->toBeNull()
        ->and($draft->lines()->count())->toBe(2)
        ->and($draft->columns()->count())->toBe(1);

    $draftDetail = $draft->lines()->where('caption', 'Revenue')->first();
    $draftTotal = $draft->lines()->where('caption', 'Total')->first();

    // Hierarchy and formula operands must point at the copied lines, not the source's.
    expect($draftDetail->parent_id)->toBe($draftTotal->id)
        ->and($draftTotal->formulas()->first()->operand_line_id)->toBe($draftDetail->id)
        ->and($draftDetail->accountBindings()->count())->toBe(1)
        ->and($draftDetail->inputs()->count())->toBe(1);

    // The copy is editable.
    $draftDetail->update(['caption' => 'Revenue (edited)']);
    expect($draftDetail->fresh()->caption)->toBe('Revenue (edited)');
});
