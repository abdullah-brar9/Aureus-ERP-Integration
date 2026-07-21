<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Template lifecycle: draft -> published -> archived, plus duplication of any
 * version into a fresh draft.
 *
 * Publishing is gated by the structural validator (errors block, warnings do
 * not) and stamps published_at. Published/archived versions are immutable at
 * the model layer (see ReportTemplate / InteractsWithReportTemplate); the only
 * way to change a published report is to spin a new draft version, edit it,
 * and publish that.
 */
class ReportTemplateVersioningService
{
    public function __construct(
        protected ReportTemplateValidator $validator,
    ) {}

    /**
     * Publish a draft. Returns the blocking errors; an empty collection means
     * the template is now published.
     *
     * @return Collection<int, ValidationIssue>
     */
    public function publish(ReportTemplate $template): Collection
    {
        $errors = $this->validator->validate($template)
            ->filter(fn (ValidationIssue $issue) => $issue->isError())
            ->values();

        if ($errors->isNotEmpty()) {
            return $errors;
        }

        $template->update([
            'status'       => TemplateStatus::PUBLISHED,
            'published_at' => now(),
        ]);

        return collect();
    }

    public function archive(ReportTemplate $template): void
    {
        $template->update(['status' => TemplateStatus::ARCHIVED]);
    }

    /**
     * Deep-copy a template into a new draft version: columns, lines (with
     * hierarchy), account bindings, formulas (operand references remapped to
     * the copied lines) and manual values all carry over.
     */
    public function newDraftVersion(ReportTemplate $source): ReportTemplate
    {
        return DB::transaction(function () use ($source) {
            $nextVersion = (int) ReportTemplate::query()
                ->where('code', $source->code)
                ->when(
                    $source->company_id === null,
                    fn ($query) => $query->whereNull('company_id'),
                    fn ($query) => $query->where('company_id', $source->company_id),
                )
                ->max('version') + 1;

            /** @var ReportTemplate $draft */
            $draft = ReportTemplate::query()->create([
                'company_id'         => $source->company_id,
                'parent_template_id' => $source->id,
                'name'               => $source->name,
                'code'               => $source->code,
                'layout_type'        => $source->layout_type,
                'currency_mode'      => $source->currency_mode,
                'entity_mode'        => $source->entity_mode,
                'status'             => TemplateStatus::DRAFT,
                'version'            => $nextVersion,
                'published_at'       => null,
                'description'        => $source->description,
            ]);

            foreach ($source->columns()->get() as $column) {
                ReportColumn::query()->create([
                    'report_template_id' => $draft->id,
                    'company_id'         => $column->company_id,
                    'label'              => $column->label,
                    'column_type'        => $column->column_type,
                    'start_month'        => $column->start_month,
                    'end_month'          => $column->end_month,
                    'year_offset'        => $column->year_offset,
                    'is_consolidated'    => $column->is_consolidated,
                ])->update(['sort' => $column->sort]);
            }

            $sourceLines = $source->lines()->with(['accountBindings', 'formulas', 'inputs'])->get();

            /** @var array<int, int> $lineIdMap source line id => copied line id */
            $lineIdMap = [];

            foreach ($sourceLines as $line) {
                $copy = ReportLine::query()->create([
                    'report_template_id' => $draft->id,
                    'company_id'         => $line->company_id,
                    'line_type'          => $line->line_type,
                    'caption'            => $line->caption,
                    'code'               => $line->code,
                    'sign'               => $line->sign,
                    'value_source'       => $line->value_source,
                    'value_basis'        => $line->value_basis,
                    'external_provider'  => $line->external_provider,
                    'is_visible'         => $line->is_visible,
                    'is_bold'            => $line->is_bold,
                    'is_check'           => $line->is_check,
                    'indent_level'       => $line->indent_level,
                    'dimension_type'     => $line->dimension_type,
                    'dimension_id'       => $line->dimension_id,
                ]);

                $lineIdMap[(int) $line->id] = (int) $copy->id;
            }

            foreach ($sourceLines as $line) {
                $copyId = $lineIdMap[(int) $line->id];

                ReportLine::query()->whereKey($copyId)->first()->update([
                    'sort'      => $line->sort,
                    'parent_id' => $line->parent_id !== null ? ($lineIdMap[(int) $line->parent_id] ?? null) : null,
                ]);

                foreach ($line->accountBindings as $binding) {
                    ReportLineAccount::query()->create([
                        'report_line_id' => $copyId,
                        'account_id'     => $binding->account_id,
                        'sign'           => $binding->sign,
                    ]);
                }

                foreach ($line->formulas as $formula) {
                    ReportLineFormula::query()->create([
                        'report_line_id'   => $copyId,
                        'purpose'          => $formula->purpose,
                        'operator'         => $formula->operator,
                        'operand_type'     => $formula->operand_type,
                        'operand_line_id'  => $formula->operand_line_id !== null
                            ? ($lineIdMap[(int) $formula->operand_line_id] ?? $formula->operand_line_id)
                            : null,
                        'operand_constant' => $formula->operand_constant,
                        'sign'             => $formula->sign,
                        'sort'             => $formula->sort,
                    ]);
                }

                foreach ($line->inputs as $input) {
                    ReportLineInput::query()->create([
                        'report_line_id' => $copyId,
                        'company_id'     => $input->company_id,
                        'date'           => $input->date,
                        'value'          => $input->value,
                    ]);
                }
            }

            return $draft->fresh();
        });
    }
}
