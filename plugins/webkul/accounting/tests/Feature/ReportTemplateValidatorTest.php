<?php

use Illuminate\Support\Collection;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportTemplateValidator;
use Webkul\Accounting\Services\ReportValueProviderRegistry;

function validatorFor(?ReportValueProviderRegistry $registry = null): ReportTemplateValidator
{
    return new ReportTemplateValidator($registry ?? new ReportValueProviderRegistry);
}

function issueCodes(Collection $issues): array
{
    return $issues->map(fn (ValidationIssue $issue) => $issue->code)->all();
}

it('accepts a well-formed template', function () {
    $account = Account::factory()->create();
    $template = ReportTemplate::factory()->create();

    $detail = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);
    ReportLineAccount::factory()->create(['report_line_id' => $detail->id, 'account_id' => $account->id]);

    $total = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $detail->id]);

    expect(validatorFor()->validate($template))->toBeEmpty();
});

it('flags a ledger line without account bindings', function () {
    $template = ReportTemplate::factory()->create();
    ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail']);

    expect(issueCodes(validatorFor()->validate($template)))->toContain('missing_account_bindings');
});

it('flags a formula line without formulas', function () {
    $template = ReportTemplate::factory()->create();
    ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);

    expect(issueCodes(validatorFor()->validate($template)))->toContain('missing_formulas');
});

it('flags formula cycles', function () {
    $template = ReportTemplate::factory()->create();

    $a = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);
    $b = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);

    ReportLineFormula::factory()->create(['report_line_id' => $a->id, 'operand_line_id' => $b->id]);
    ReportLineFormula::factory()->create(['report_line_id' => $b->id, 'operand_line_id' => $a->id]);

    expect(issueCodes(validatorFor()->validate($template)))->toContain('formula_cycle');
});

it('flags operands referencing another template and non-computable targets', function () {
    $template = ReportTemplate::factory()->create();
    $other = ReportTemplate::factory()->create();

    $foreign = ReportLine::factory()->create(['report_template_id' => $other->id, 'line_type' => 'detail']);
    $header = ReportLine::factory()->sectionHeader()->create(['report_template_id' => $template->id]);

    $total = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $foreign->id]);
    ReportLineFormula::factory()->create(['report_line_id' => $total->id, 'operand_line_id' => $header->id]);

    $codes = issueCodes(validatorFor()->validate($template));

    expect($codes)->toContain('cross_template_operand')
        ->and($codes)->toContain('operand_not_computable');
});

it('flags invalid operand payloads', function () {
    $template = ReportTemplate::factory()->create();

    $total = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id]);

    ReportLineFormula::factory()->create([
        'report_line_id' => $total->id, 'operand_type' => 'line', 'operand_line_id' => null,
    ]);
    ReportLineFormula::factory()->create([
        'report_line_id' => $total->id, 'operand_type' => 'constant', 'operand_line_id' => null, 'operand_constant' => null,
    ]);

    $codes = issueCodes(validatorFor()->validate($template));

    expect($codes)->toContain('operand_line_missing_id')
        ->and($codes)->toContain('operand_constant_missing_value');
});

it('flags missing and unregistered external providers', function () {
    $template = ReportTemplate::factory()->create();

    ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail',
        'value_source'       => ValueSource::EXTERNAL, 'external_provider' => null,
    ]);
    ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail',
        'value_source'       => ValueSource::EXTERNAL, 'external_provider' => 'ghost',
    ]);

    $registry = new ReportValueProviderRegistry;
    $codes = issueCodes(validatorFor($registry)->validate($template));

    expect($codes)->toContain('missing_external_provider')
        ->and($codes)->toContain('unregistered_external_provider');

    $registry->register('ghost', fn () => 0.0);

    expect(issueCodes(validatorFor($registry)->validate($template)))
        ->not->toContain('unregistered_external_provider');
});

it('warns about duplicate line sort positions', function () {
    $template = ReportTemplate::factory()->create();

    $a = ReportLine::factory()->spacer()->create(['report_template_id' => $template->id]);
    $b = ReportLine::factory()->spacer()->create(['report_template_id' => $template->id]);

    // Sortable assigns unique positions on create; collide them explicitly.
    $a->update(['sort' => 7]);
    $b->update(['sort' => 7]);

    expect(issueCodes(validatorFor()->validate($template)))->toContain('duplicate_line_sort');
});

it('flags invalid column definitions', function () {
    $template = ReportTemplate::factory()->create();

    ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => null]);

    expect(issueCodes(validatorFor()->validate($template)))->toContain('invalid_column_definition');
});

it('warns that dimension filters are not applied yet', function () {
    $account = Account::factory()->create();
    $template = ReportTemplate::factory()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail',
        'dimension_type'     => 'analytic_account', 'dimension_id' => 1,
    ]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id]);

    $issues = validatorFor()->validate($template);

    expect(issueCodes($issues))->toContain('dimension_not_applied')
        ->and($issues->first(fn (ValidationIssue $i) => $i->code === 'dimension_not_applied')->isError())->toBeFalse();
});

it('flags duplicate code and version among global templates', function () {
    ReportTemplate::factory()->create(['company_id' => null, 'code' => 'bs-group', 'version' => 1]);
    $duplicate = ReportTemplate::factory()->create(['company_id' => null, 'code' => 'bs-group', 'version' => 1]);

    expect(issueCodes(validatorFor()->validate($duplicate)))->toContain('duplicate_global_code');
});

it('reports check rows that do not evaluate to zero', function () {
    $template = ReportTemplate::factory()->create();

    $check = ReportLine::factory()->subtotal()->create([
        'report_template_id' => $template->id, 'caption' => 'Check', 'is_check' => true,
    ]);

    $results = collect([
        new ReportLineValue(
            lineId: $check->id, parentId: null, lineType: LineType::SUBTOTAL,
            caption: 'Check', code: null, isVisible: true, isBold: false,
            indentLevel: 0, sort: 1, values: ['2025' => 0.005, '2024' => -3.2], isCheck: true,
        ),
    ]);

    $violations = validatorFor()->checkViolations($results, tolerance: 0.01);

    expect($violations)->toHaveCount(1)
        ->and($violations->first()->code)->toBe('check_row_violation')
        ->and($violations->first()->lineId)->toBe($check->id);
});
