<?php

use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Services\Formula\CycleDetector;

function cdFormula(int $operandLineId): ReportLineFormula
{
    $formula = new ReportLineFormula();
    $formula->forceFill([
        'operator'         => FormulaOperator::ADD,
        'operand_type'     => FormulaOperandType::LINE,
        'operand_line_id'  => $operandLineId,
        'operand_constant' => null,
        'sign'             => 1,
        'sort'             => 0,
    ]);

    return $formula;
}

function cdLine(int $id, array $operandLineIds = []): ReportLine
{
    $line = new ReportLine();
    $line->forceFill(['id' => $id, 'line_type' => 'subtotal', 'sign' => 1]);
    $line->setRelation('formulas', collect(array_map('cdFormula', $operandLineIds)));

    return $line;
}

it('accepts an acyclic chain of nested subtotals', function () {
    // 3 -> 2 -> 1 (no cycle)
    $lines = [
        cdLine(1, []),
        cdLine(2, [1]),
        cdLine(3, [2]),
    ];

    CycleDetector::forLines($lines)->assertAcyclic();

    expect(true)->toBeTrue(); // reached here without throwing
});

it('detects a direct self-reference', function () {
    $lines = [cdLine(1, [1])];

    CycleDetector::forLines($lines)->assertAcyclic();
})->throws(RuntimeException::class, 'Circular reference');

it('detects an indirect cycle', function () {
    // 1 -> 2 -> 3 -> 1
    $lines = [
        cdLine(1, [2]),
        cdLine(2, [3]),
        cdLine(3, [1]),
    ];

    CycleDetector::forLines($lines)->assertAcyclic();
})->throws(RuntimeException::class, 'Circular reference');
