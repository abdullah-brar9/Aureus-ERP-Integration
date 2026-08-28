<?php

namespace Webkul\Accounting\Services\Formula;

use RuntimeException;
use Webkul\Accounting\Models\ReportLine;

/**
 * Detects circular dependencies among computed (subtotal) report lines.
 *
 * A computed line may reference other lines through report_line_formulas, and
 * those may themselves be computed. Evaluation requires the dependency graph to
 * be acyclic; this class validates that and throws a clear error otherwise.
 */
class CycleDetector
{
    /**
     * @param  array<int, ReportLine>  $lines  all lines of the report keyed arbitrarily
     */
    public function __construct(protected array $lines) {}

    /**
     * @param  iterable<ReportLine>  $lines
     */
    public static function forLines(iterable $lines): self
    {
        $indexed = [];

        foreach ($lines as $line) {
            $indexed[(int) $line->id] = $line;
        }

        return new self($indexed);
    }

    /**
     * Throws RuntimeException if any computed line participates in a cycle.
     */
    public function assertAcyclic(): void
    {
        $state = []; // line_id => 0 unvisited, 1 visiting, 2 done

        foreach ($this->lines as $line) {
            $this->visit((int) $line->id, $state, []);
        }
    }

    /**
     * @param  array<int, int>  $state
     * @param  array<int, int>  $stack
     */
    protected function visit(int $lineId, array &$state, array $stack): void
    {
        $current = $state[$lineId] ?? 0;

        if ($current === 2) {
            return;
        }

        if ($current === 1) {
            $chain = implode(' -> ', [...$stack, $lineId]);

            throw new RuntimeException("Circular reference detected in report formula chain: {$chain}");
        }

        $state[$lineId] = 1;

        $line = $this->lines[$lineId] ?? null;

        if ($line !== null) {
            $line->loadMissing('formulas');

            foreach ($line->formulas as $formula) {
                $operandLineId = $formula->operand_line_id !== null ? (int) $formula->operand_line_id : null;

                if ($operandLineId !== null) {
                    $this->visit($operandLineId, $state, [...$stack, $lineId]);
                }
            }
        }

        $state[$lineId] = 2;
    }
}
