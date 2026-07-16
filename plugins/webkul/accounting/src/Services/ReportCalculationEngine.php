<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Accounting\Services\Formula\CycleDetector;
use Webkul\Accounting\Services\Formula\FormulaEvaluator;

/**
 * Computes every line's value, for every period, for one report template.
 *
 * Flow per report:
 *   1. Load lines (ordered) with their bindings and formulas.
 *   2. Assert the computed-line dependency graph is acyclic.
 *   3. Resolve each detail line's signed account set once.
 *   4. For each period:
 *        a. detail lines  -> signed sum of ledger balances (via repository)
 *        b. computed lines -> evaluated in dependency order (via evaluator)
 *   5. Emit one ReportLineValue per line, preserving order.
 *
 * Nothing about the layout or the formulas is hardcoded; all of it is read from
 * the Stage 2 tables. The engine is agnostic to how many periods it is given,
 * so a period_total report (one period) and a monthly_matrix report (twelve
 * months + total) use the identical code path.
 */
class ReportCalculationEngine
{
    public function __construct(
        protected LedgerBalanceRepository $ledger,
        protected AccountBindingService $bindings,
        protected FormulaEvaluator $evaluator,
    ) {}

    /**
     * @param  array<int, ReportPeriod>  $periods
     * @return Collection<int, ReportLineValue>  in template line order
     */
    public function calculate(ReportTemplate $template, array $periods, ReportContext $context, bool $cumulative = false): Collection
    {
        /** @var Collection<int, ReportLine> $lines */
        $lines = $template->lines()
            ->with(['accountBindings', 'formulas'])
            ->orderBy('sort')
            ->get();

        CycleDetector::forLines($lines)->assertAcyclic();

        $detailLines   = $lines->filter(fn (ReportLine $l) => $this->typeOf($l) === LineType::DETAIL);
        $computedLines = $lines->filter(fn (ReportLine $l) => $this->typeOf($l) === LineType::SUBTOTAL);

        // Resolve signed account sets for detail lines once (period-independent).
        $signedAccountsByLine = [];
        $allAccountIds        = [];

        foreach ($detailLines as $line) {
            $signed = $this->bindings->resolveSignedAccounts($line);
            $signedAccountsByLine[(int) $line->id] = $signed;
            $allAccountIds = [...$allAccountIds, ...array_keys($signed)];
        }

        $allAccountIds = array_values(array_unique(array_map('intval', $allAccountIds)));

        // Determine evaluation order for computed lines (dependencies first).
        $computedOrder = $this->topologicalOrder($computedLines, $lines);

        // Per-period value maps: line_id => value.
        $valuesByPeriodLine = [];

        foreach ($periods as $period) {
            $balances = $cumulative
                ? $this->ledger->cumulativeBalancesForAccounts($allAccountIds, $period, $context)
                : $this->ledger->balancesForAccounts($allAccountIds, $period, $context);

            $lineValues = [];

            // a. detail lines
            foreach ($detailLines as $line) {
                $sum = 0.0;
                foreach ($signedAccountsByLine[(int) $line->id] as $accountId => $sign) {
                    $sum += ((float) ($balances[$accountId] ?? 0.0)) * ($sign === -1 ? -1.0 : 1.0);
                }

                $lineSign = (int) $line->sign === -1 ? -1.0 : 1.0;
                $lineValues[(int) $line->id] = $sum * $lineSign;
            }

            // b. computed lines, in dependency order
            foreach ($computedOrder as $line) {
                $value = $this->evaluator->evaluate($line, $lineValues);

                $lineSign = (int) $line->sign === -1 ? -1.0 : 1.0;
                $lineValues[(int) $line->id] = $value * $lineSign;
            }

            $valuesByPeriodLine[$period->key] = $lineValues;
        }

        // Assemble output in template order.
        return $lines->map(function (ReportLine $line) use ($periods, $valuesByPeriodLine) {
            $type = $this->typeOf($line);

            $carries = ! in_array($type, [LineType::SECTION_HEADER, LineType::SPACER], true);

            $values = [];
            if ($carries) {
                foreach ($periods as $period) {
                    $values[$period->key] = (float) ($valuesByPeriodLine[$period->key][(int) $line->id] ?? 0.0);
                }
            }

            return ReportLineValue::fromLine($line, $values);
        })->values();
    }

    /**
     * Order computed lines so that any computed line referenced by another is
     * evaluated first. Falls back to `sort` order for independent lines.
     *
     * @param  Collection<int, ReportLine>  $computedLines
     * @param  Collection<int, ReportLine>  $allLines
     * @return array<int, ReportLine>
     */
    protected function topologicalOrder(Collection $computedLines, Collection $allLines): array
    {
        $byId = [];
        foreach ($allLines as $line) {
            $byId[(int) $line->id] = $line;
        }

        $computedIds = $computedLines->map(fn (ReportLine $l) => (int) $l->id)->all();
        $computedSet = array_flip($computedIds);

        $ordered = [];
        $state   = []; // 0/absent = unvisited, 1 = visiting, 2 = done

        $visit = function (int $lineId) use (&$visit, &$ordered, &$state, $byId, $computedSet): void {
            if (($state[$lineId] ?? 0) === 2) {
                return;
            }

            $state[$lineId] = 1;

            $line = $byId[$lineId] ?? null;

            if ($line !== null) {
                $line->loadMissing('formulas');

                foreach ($line->formulas as $formula) {
                    $operandLineId = $formula->operand_line_id !== null ? (int) $formula->operand_line_id : null;

                    // Only recurse into operands that are themselves computed.
                    if ($operandLineId !== null && isset($computedSet[$operandLineId])) {
                        $visit($operandLineId);
                    }
                }

                if (isset($computedSet[$lineId])) {
                    $ordered[] = $line;
                }
            }

            $state[$lineId] = 2;
        };

        // Visit in sort order for deterministic output.
        foreach ($computedLines->sortBy('sort')->values() as $line) {
            $visit((int) $line->id);
        }

        return $ordered;
    }

    protected function typeOf(ReportLine $line): LineType
    {
        return $line->line_type instanceof LineType
            ? $line->line_type
            : LineType::from((string) $line->line_type);
    }
}
