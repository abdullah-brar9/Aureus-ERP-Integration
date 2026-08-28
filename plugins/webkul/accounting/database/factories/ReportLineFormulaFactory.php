<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;
use Webkul\Accounting\Enums\FormulaPurpose;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineFormula;

/**
 * @extends Factory<ReportLineFormula>
 */
class ReportLineFormulaFactory extends Factory
{
    protected $model = ReportLineFormula::class;

    public function definition(): array
    {
        return [
            'report_line_id'   => ReportLine::factory(),
            'purpose'          => FormulaPurpose::VALUE,
            'operator'         => FormulaOperator::ADD,
            'operand_type'     => FormulaOperandType::LINE,
            'operand_line_id'  => ReportLine::factory(),
            'operand_constant' => null,
            'sign'             => 1,
            'sort'             => fake()->numberBetween(0, 20),
        ];
    }

    public function constant(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'operand_type'     => FormulaOperandType::CONSTANT,
            'operand_line_id'  => null,
            'operand_constant' => $value,
        ]);
    }

    public function operator(FormulaOperator $operator): static
    {
        return $this->state(fn (array $attributes) => [
            'operator' => $operator,
        ]);
    }

    public function consolidation(): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => FormulaPurpose::CONSOLIDATION,
        ]);
    }
}
