<?php

namespace Webkul\Accounting\Services\Formula;

use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;
use Webkul\Accounting\Models\ReportLine;

/**
 * Pure evaluation of a single computed (subtotal / ratio / KPI) line for a
 * single period.
 *
 * The evaluator is deliberately free of database access: it is handed a map of
 * already-computed line values (line_id => value) for the current period and
 * folds the line's ordered formula operands into a single number. This keeps it
 * deterministic and unit-testable, and means the same engine serves sums,
 * differences, ratios (e.g. revenue / parcels) and percentages
 * (e.g. gm / revenue * 100) with no special cases.
 */
class FormulaEvaluator
{
    /**
     * Evaluate one computed line for one period.
     *
     * @param  array<int, float>  $lineValues  line_id => already computed value (this period)
     */
    public function evaluate(ReportLine $line, array $lineValues): float
    {
        $line->loadMissing('formulas');

        $operands = $line->formulas
            ->sortBy('sort')
            ->values();

        if ($operands->isEmpty()) {
            return 0.0;
        }

        $result = 0.0;

        foreach ($operands as $index => $formula) {
            $operandValue = $this->operandValue($formula, $lineValues);

            $sign = (int) $formula->sign === -1 ? -1.0 : 1.0;
            $operandValue *= $sign;

            $operator = $formula->operator instanceof FormulaOperator
                ? $formula->operator
                : FormulaOperator::from((string) $formula->operator);

            // The first operand seeds the running result. For +/- the seed is
            // added/subtracted from 0.0; for * / the seed is taken directly so
            // that a leading multiply/divide behaves intuitively.
            if ($index === 0) {
                $result = match ($operator) {
                    FormulaOperator::ADD      => 0.0 + $operandValue,
                    FormulaOperator::SUBTRACT => 0.0 - $operandValue,
                    FormulaOperator::MULTIPLY => $operandValue,
                    FormulaOperator::DIVIDE   => $operandValue,
                };

                continue;
            }

            $result = $this->apply($result, $operator, $operandValue);
        }

        return $result;
    }

    /**
     * Resolve a single operand to its numeric value.
     *
     * @param  array<int, float>  $lineValues
     */
    protected function operandValue($formula, array $lineValues): float
    {
        $type = $formula->operand_type instanceof FormulaOperandType
            ? $formula->operand_type
            : FormulaOperandType::from((string) $formula->operand_type);

        if ($type === FormulaOperandType::CONSTANT) {
            return (float) ($formula->operand_constant ?? 0.0);
        }

        $operandLineId = $formula->operand_line_id !== null ? (int) $formula->operand_line_id : null;

        if ($operandLineId === null) {
            return 0.0;
        }

        return (float) ($lineValues[$operandLineId] ?? 0.0);
    }

    /**
     * Apply one operator to the running result. Division by zero yields 0.0
     * rather than an error, matching the defensive handling used elsewhere in
     * the reporting layer.
     */
    protected function apply(float $carry, FormulaOperator $operator, float $operand): float
    {
        return match ($operator) {
            FormulaOperator::ADD      => $carry + $operand,
            FormulaOperator::SUBTRACT => $carry - $operand,
            FormulaOperator::MULTIPLY => $carry * $operand,
            FormulaOperator::DIVIDE   => $operand === 0.0 ? 0.0 : $carry / $operand,
        };
    }
}
