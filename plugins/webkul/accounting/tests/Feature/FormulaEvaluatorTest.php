<?php

use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Services\Formula\FormulaEvaluator;

/**
 * These tests exercise the evaluator purely in memory. ReportLine and
 * ReportLineFormula are instantiated (not persisted) and the formulas relation
 * is set directly, so no database is required.
 */

function makeFormula(array $attributes): ReportLineFormula
{
    $formula = new ReportLineFormula();
    $formula->forceFill(array_merge([
        'operator'         => FormulaOperator::ADD,
        'operand_type'     => FormulaOperandType::LINE,
        'operand_line_id'  => null,
        'operand_constant' => null,
        'sign'             => 1,
        'sort'             => 0,
    ], $attributes));

    return $formula;
}

function makeComputedLine(int $id, array $formulas): ReportLine
{
    $line = new ReportLine();
    $line->forceFill(['id' => $id, 'line_type' => 'subtotal', 'sign' => 1]);
    $line->setRelation('formulas', collect($formulas));

    return $line;
}

it('adds line operands (e.g. a simple subtotal)', function () {
    // Subtotal = line(10) + line(11)
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 10, 'sort' => 0]),
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 11, 'sort' => 1]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 100.0, 11 => 25.0]);

    expect($value)->toBe(125.0);
});

it('subtracts line operands (e.g. GM minus subsidies)', function () {
    // CM = line(10) - line(11) - line(12)
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 10, 'sort' => 0]),
        makeFormula(['operator' => FormulaOperator::SUBTRACT, 'operand_line_id' => 11, 'sort' => 1]),
        makeFormula(['operator' => FormulaOperator::SUBTRACT, 'operand_line_id' => 12, 'sort' => 2]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 500.0, 11 => 120.0, 12 => 80.0]);

    expect($value)->toBe(300.0);
});

it('divides to produce a ratio (e.g. RPS = revenue / parcels)', function () {
    // RPS = line(10) / line(11)
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 10, 'sort' => 0]),
        makeFormula(['operator' => FormulaOperator::DIVIDE, 'operand_line_id' => 11, 'sort' => 1]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 1000.0, 11 => 40.0]);

    expect($value)->toBe(25.0);
});

it('computes a percentage using a constant operand (e.g. margin % = gm / revenue * 100)', function () {
    // margin% = line(10) / line(11) * 100
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 10, 'sort' => 0]),
        makeFormula(['operator' => FormulaOperator::DIVIDE, 'operand_line_id' => 11, 'sort' => 1]),
        makeFormula([
            'operator'     => FormulaOperator::MULTIPLY,
            'operand_type' => FormulaOperandType::CONSTANT,
            'operand_constant' => 100,
            'sort'         => 2,
        ]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 300.0, 11 => 1200.0]);

    expect($value)->toBe(25.0);
});

it('returns 0.0 on division by zero rather than erroring', function () {
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 10, 'sort' => 0]),
        makeFormula(['operator' => FormulaOperator::DIVIDE, 'operand_line_id' => 11, 'sort' => 1]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 1000.0, 11 => 0.0]);

    expect($value)->toBe(0.0);
});

it('honours an operand sign flag', function () {
    // result = -line(10) + line(11)
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 10, 'sign' => -1, 'sort' => 0]),
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 11, 'sort' => 1]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 100.0, 11 => 30.0]);

    expect($value)->toBe(-70.0);
});

it('treats a line with no formulas as zero', function () {
    $line = makeComputedLine(1, []);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 100.0]);

    expect($value)->toBe(0.0);
});

it('resolves a missing referenced line as zero', function () {
    $line = makeComputedLine(1, [
        makeFormula(['operator' => FormulaOperator::ADD, 'operand_line_id' => 999, 'sort' => 0]),
    ]);

    $value = (new FormulaEvaluator())->evaluate($line, [10 => 100.0]);

    expect($value)->toBe(0.0);
});
