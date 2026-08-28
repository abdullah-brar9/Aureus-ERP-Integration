<?php

namespace Webkul\Accounting\Services\Coa;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Reads a Chart-of-Accounts workbook (CSV or XLSX) into a zero-indexed array
 * of rows of cells, using dependencies already present in the project
 * (native CSV parsing for .csv, PhpSpreadsheet for .xlsx). No structure is
 * assumed here — header detection happens downstream.
 */
class CoaSheetReader
{
    /**
     * @return array<int, array<int, mixed>>
     */
    public function read(string $path): array
    {
        return $this->readWithSource($path)['rows'];
    }

    /**
     * @return array{rows: array<int, array<int, mixed>>, sheet_name: ?string}
     */
    public function readWithSource(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt'          => ['rows' => $this->readCsv($path), 'sheet_name' => null],
            'xlsx', 'xls', 'xlsm' => $this->readSpreadsheet($path),
            default               => throw new RuntimeException("Unsupported file type: .{$extension} (use CSV or XLSX)."),
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV: {$path}");
        }

        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rows[] = array_map(fn ($c) => (string) ($c ?? ''), $cells);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function readSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        $sheet = $spreadsheet->getActiveSheet();
        foreach ($spreadsheet->getAllSheets() as $candidate) {
            $rows = $this->spreadsheetRows($candidate);
            $containsCoaHeader = collect($rows)->contains(function (array $row): bool {
                $headers = array_map(
                    fn (mixed $cell): string => mb_strtolower(trim((string) $cell)),
                    $row,
                );

                return in_array('nature', $headers, true)
                    && in_array('code', $headers, true)
                    && in_array('title', $headers, true);
            });

            if ($containsCoaHeader) {
                $sheet = $candidate;

                break;
            }
        }

        return [
            'rows'       => $this->spreadsheetRows($sheet),
            'sheet_name' => $sheet->getTitle(),
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function spreadsheetRows(Worksheet $sheet): array
    {
        $range = 'A1:'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow();

        return $sheet->rangeToArray($range, null, true, false, false);
    }
}
