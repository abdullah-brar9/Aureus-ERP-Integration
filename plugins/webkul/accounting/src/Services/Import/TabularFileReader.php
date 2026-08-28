<?php

namespace Webkul\Accounting\Services\Import;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Webkul\Accounting\Models\ImportProfile;

final class TabularFileReader
{
    /**
     * @return array{sheet: string|null, headers: array<int, string>, rows: array<int, array{row_number: int, values: array<int, mixed>}>}
     */
    public function read(string $path, ImportProfile $profile): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('The import file does not exist.');
        }

        $reader = $profile->file_type === 'csv' ? new Csv : IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if ($reader instanceof Csv) {
            $reader->setDelimiter($profile->delimiter ?: ',');
            $reader->setInputEncoding($profile->encoding ?: 'UTF-8');
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $profile->sheet_name
            ? $spreadsheet->getSheetByName($profile->sheet_name)
            : $spreadsheet->getSheet(0);

        if ($worksheet === null) {
            throw new InvalidArgumentException("Worksheet [{$profile->sheet_name}] was not found.");
        }

        $matrix = $worksheet->toArray(null, true, true, false);
        $headerIndex = max(0, $profile->header_row - 1);
        $headers = array_map(fn (mixed $value): string => trim((string) $value), $matrix[$headerIndex] ?? []);
        if ($headers === [] || collect($headers)->every(fn (string $header): bool => $header === '')) {
            throw new InvalidArgumentException('The configured header row is blank.');
        }

        $rows = [];
        $dataStartIndex = max($headerIndex + 1, $profile->data_start_row - 1) + $profile->skip_rows;
        for ($index = $dataStartIndex; $index < count($matrix); $index++) {
            $values = $matrix[$index];
            $isBlank = collect($values)->every(fn (mixed $value): bool => $value === null || trim((string) $value) === '');
            if ($isBlank && $profile->blank_row_rule === 'stop') {
                break;
            }
            if ($isBlank) {
                continue;
            }
            if ($this->stopRuleMatches($values, $headers, (array) $profile->stop_rule)) {
                break;
            }

            $rows[] = ['row_number' => $index + 1, 'values' => $values];
        }

        $spreadsheet->disconnectWorksheets();

        return ['sheet' => $worksheet->getTitle(), 'headers' => $headers, 'rows' => $rows];
    }

    /** @param array<int, mixed> $values @param array<int, string> $headers @param array<string, mixed> $rule */
    private function stopRuleMatches(array $values, array $headers, array $rule): bool
    {
        if ($rule === []) {
            return false;
        }

        $column = (string) ($rule['column'] ?? '');
        $index = array_search($column, $headers, true);
        if ($index === false) {
            return false;
        }

        $actual = trim((string) ($values[$index] ?? ''));

        return match ($rule['operator'] ?? 'equals') {
            'equals'   => $actual === (string) ($rule['value'] ?? ''),
            'contains' => str_contains(mb_strtolower($actual), mb_strtolower((string) ($rule['value'] ?? ''))),
            'blank'    => $actual === '',
            default    => false,
        };
    }
}
