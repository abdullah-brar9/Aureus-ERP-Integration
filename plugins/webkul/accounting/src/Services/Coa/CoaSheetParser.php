<?php

namespace Webkul\Accounting\Services\Coa;

use Webkul\Accounting\Data\Coa\CoaColumnMap;
use Webkul\Accounting\Data\Coa\CoaRow;

/**
 * Turns the raw sheet rows + resolved column map into typed CoaRow objects.
 * A data row is any row (below the header) that has both a Code and a Title;
 * blank/separator rows are skipped. Amounts are parsed leniently so "$",
 * thousands separators, parentheses-negatives and "-" placeholders all work.
 */
class CoaSheetParser
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, CoaRow>
     */
    public function parse(array $rows, CoaColumnMap $map): array
    {
        $parsed = [];
        $headers = array_map(fn ($header) => trim((string) $header), $rows[$map->headerRowIndex] ?? []);

        for ($i = $map->firstDataRowIndex(); $i < count($rows); $i++) {
            $row = $rows[$i];

            $code = trim((string) ($row[$map->codeCol] ?? ''));
            $title = trim((string) ($row[$map->titleCol] ?? ''));

            if ($code === '' && $title === '') {
                continue;
            }

            $classifications = [];
            $classificationValues = [];
            foreach ($map->classificationCols as $col) {
                $value = trim((string) ($row[$col] ?? ''));
                $classificationValues[$headers[$col] ?? "Classification {$col}"] = $value;
                if ($value !== '') {
                    $classifications[] = $value;
                }
            }

            $rawRow = array_values($row);
            $rawRowByHeader = [];
            foreach ($headers as $column => $header) {
                $rawRowByHeader[$header !== '' ? $header : 'Column '.($column + 1)] = $row[$column] ?? null;
            }

            $parsed[] = new CoaRow(
                sheetRow: $i + 1,
                nature: trim((string) ($row[$map->natureCol] ?? '')),
                classifications: $classifications,
                code: $code,
                title: $title,
                openingDebit: $this->numberAt($row, $map->openingDebitCol),
                openingCredit: $this->numberAt($row, $map->openingCreditCol),
                movementDebit: $this->numberAt($row, $map->movementDebitCol),
                movementCredit: $this->numberAt($row, $map->movementCreditCol),
                adjustmentDebit: $this->numberAt($row, $map->adjustmentDebitCol),
                adjustmentCredit: $this->numberAt($row, $map->adjustmentCreditCol),
                closingDebit: $this->numberAt($row, $map->closingDebitCol),
                closingCredit: $this->numberAt($row, $map->closingCreditCol),
                classificationValues: $classificationValues,
                rawRow: $rawRow,
                rawRowByHeader: $rawRowByHeader,
                sourceHeaders: $headers,
            );
        }

        return $parsed;
    }

    protected function numberAt(array $row, ?int $column): float
    {
        return $column === null ? 0.0 : $this->number($row[$column] ?? null);
    }

    protected function number(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $text = trim((string) $value);

        if ($text === '' || $text === '-') {
            return 0.0;
        }

        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');

        $clean = preg_replace('/[^0-9.\-]/', '', $text);

        if ($clean === '' || $clean === '-' || $clean === '.') {
            return 0.0;
        }

        $number = (float) $clean;

        return $negative ? -abs($number) : $number;
    }
}
