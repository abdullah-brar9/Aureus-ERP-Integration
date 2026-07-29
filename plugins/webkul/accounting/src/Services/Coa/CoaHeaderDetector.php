<?php

namespace Webkul\Accounting\Services\Coa;

use RuntimeException;
use Webkul\Accounting\Data\Coa\CoaColumnMap;

/**
 * Finds the real header row in a Chart-of-Accounts sheet (which begins with
 * title/metadata rows) and resolves the column layout.
 *
 * The header row is the first row containing the mandatory labels Nature, Code
 * and Title. Classification columns are discovered by their "Classification N"
 * labels (the sample skips 6 and uses "Classification 7", so the count/labels
 * are not assumed). The eight Debit/Credit balance columns follow the Title
 * column in the fixed Opening/Movement/Adjustment/Closing order.
 */
class CoaHeaderDetector
{
    /**
     * @param  array<int, array<int, mixed>>  $rows  zero-indexed rows of cells
     */
    public function detect(array $rows): CoaColumnMap
    {
        foreach ($rows as $index => $row) {
            $labels = array_map(
                fn ($cell) => mb_strtolower(trim((string) $cell)),
                $row,
            );

            $natureCol = $this->indexOf($labels, 'nature');
            $codeCol = $this->indexOf($labels, 'code');
            $titleCol = $this->indexOf($labels, 'title');

            if ($natureCol === null || $codeCol === null || $titleCol === null) {
                continue;
            }

            $classificationCols = [];
            foreach ($labels as $col => $label) {
                if (str_starts_with($label, 'classification')) {
                    $classificationCols[] = $col;
                }
            }
            sort($classificationCols);

            // A structure-only workbook ends at Title. The legacy migration
            // fixture has eight balance columns immediately after Title.
            $b = $titleCol;
            $columnsAfterTitle = count($row) - ($titleCol + 1);

            if ($columnsAfterTitle > 0 && $columnsAfterTitle < 8) {
                throw new RuntimeException(
                    'Header row has a partial balance layout. Supply either no balance columns or all eight Opening/Movement/Adjustment/Closing debit/credit columns after "Title".'
                );
            }

            $hasBalances = $columnsAfterTitle >= 8;

            return new CoaColumnMap(
                headerRowIndex: $index,
                natureCol: $natureCol,
                classificationCols: $classificationCols,
                codeCol: $codeCol,
                titleCol: $titleCol,
                openingDebitCol: $hasBalances ? $b + 1 : null,
                openingCreditCol: $hasBalances ? $b + 2 : null,
                movementDebitCol: $hasBalances ? $b + 3 : null,
                movementCreditCol: $hasBalances ? $b + 4 : null,
                adjustmentDebitCol: $hasBalances ? $b + 5 : null,
                adjustmentCreditCol: $hasBalances ? $b + 6 : null,
                closingDebitCol: $hasBalances ? $b + 7 : null,
                closingCreditCol: $hasBalances ? $b + 8 : null,
            );
        }

        throw new RuntimeException('Could not find a header row containing "Nature", "Code" and "Title".');
    }

    /**
     * @param  array<int, string>  $labels
     */
    protected function indexOf(array $labels, string $needle): ?int
    {
        foreach ($labels as $col => $label) {
            if ($label === $needle) {
                return $col;
            }
        }

        return null;
    }
}
