<?php

namespace Webkul\Accounting\Services\Bank;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Webkul\Accounting\Contracts\BankStatementParser;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;
use Webkul\Accounting\Data\Bank\NormalizedBankTransaction;

abstract class AbstractSpreadsheetBankStatementParser implements BankStatementParser
{
    abstract protected function bankName(): string;

    abstract protected function detectionToken(): string;

    public function supports(string $path, ?string $sheetName = null): bool
    {
        if ($sheetName !== null && str_contains(mb_strtolower($sheetName), mb_strtolower($this->detectionToken()))) {
            return true;
        }

        foreach ($this->availableSources($path) as $source) {
            if (str_contains(mb_strtolower($source), mb_strtolower($this->detectionToken()))) {
                return true;
            }
        }

        return false;
    }

    public function parse(string $path, ?string $sheetName = null): NormalizedBankStatement
    {
        [$rows, $resolvedSheet] = $this->rows($path, $sheetName);
        $headerRow = $this->findTransactionHeader($rows);

        $accountTitle = trim((string) ($rows[1][1] ?? ''));
        $accountNumber = trim((string) ($rows[2][1] ?? ''));
        $period = trim((string) ($rows[3][1] ?? ''));
        $currency = trim((string) ($rows[4][1] ?? ''));
        [$startDate, $endDate] = $this->parsePeriod($period);

        $transactions = [];
        for ($index = $headerRow + 1; $index < count($rows); $index++) {
            $row = array_values($rows[$index]);
            $description = trim((string) ($row[2] ?? ''));
            $transactionDate = $this->date($row[0] ?? null);

            if ($description === '' && $transactionDate === null) {
                continue;
            }

            if ($transactionDate === null) {
                $transactionDate = '';
            }

            $transactions[] = new NormalizedBankTransaction(
                transactionDate: $transactionDate,
                valueDate: $this->date($row[1] ?? null),
                description: $description,
                reference: $this->nullableString($row[3] ?? null),
                debit: $this->number($row[4] ?? null),
                credit: $this->number($row[5] ?? null),
                runningBalance: $this->nullableNumber($row[6] ?? null),
                sourceRow: $index + 1,
                rawRow: $row,
            );
        }

        if ($accountNumber === '' || $transactions === []) {
            throw new RuntimeException("{$this->bankName()} statement metadata or transaction rows are missing.");
        }

        return new NormalizedBankStatement(
            bank: $this->bankName(),
            bankAccountNumber: $accountNumber,
            accountTitle: $accountTitle,
            currency: $currency,
            statementStartDate: $startDate,
            statementEndDate: $endDate,
            openingBalance: $this->number($rows[1][6] ?? null),
            totalDebits: $this->number($rows[2][6] ?? null),
            totalCredits: $this->number($rows[3][6] ?? null),
            closingBalance: $this->number($rows[4][6] ?? null),
            parser: $this->key(),
            sourceSheet: $resolvedSheet,
            rawHeader: array_values(array_slice($rows, 0, $headerRow + 1)),
            transactions: $transactions,
        );
    }

    /**
     * @return array{0: array<int, array<int, mixed>>, 1: ?string}
     */
    protected function rows(string $path, ?string $sheetName): array
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'], true)) {
            $rows = [];
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new RuntimeException("Unable to read bank statement: {$path}");
            }

            try {
                while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rows[] = array_values($row);
                }
            } finally {
                fclose($handle);
            }

            return [$rows, null];
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $sheetName !== null ? $spreadsheet->getSheetByName($sheetName) : null;

        if ($sheet === null) {
            foreach ($spreadsheet->getWorksheetIterator() as $candidate) {
                $titleAndHeading = $candidate->getTitle().' '.(string) $candidate->getCell('A1')->getValue();
                if (str_contains(mb_strtolower($titleAndHeading), mb_strtolower($this->detectionToken()))) {
                    $sheet = $candidate;
                    break;
                }
            }
        }

        if ($sheet === null) {
            throw new RuntimeException("No {$this->bankName()} statement sheet was found.");
        }

        $range = 'A1:'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow();

        return [$sheet->rangeToArray($range, null, true, true, false), $sheet->getTitle()];
    }

    /**
     * @return array<int, string>
     */
    protected function availableSources(string $path): array
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['csv', 'txt'], true)) {
            $handle = fopen($path, 'r');
            $first = $handle === false ? '' : (string) fgets($handle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            return [$first, basename($path)];
        }

        $spreadsheet = IOFactory::load($path);

        return array_map(
            fn ($sheet) => $sheet->getTitle().' '.(string) $sheet->getCell('A1')->getValue(),
            $spreadsheet->getAllSheets(),
        );
    }

    protected function findTransactionHeader(array $rows): int
    {
        foreach ($rows as $index => $row) {
            $labels = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $row);
            if (in_array('transaction date', $labels, true)
                && in_array('description', $labels, true)
                && collect($labels)->contains(fn (string $label) => str_starts_with($label, 'debit'))
                && collect($labels)->contains(fn (string $label) => str_starts_with($label, 'credit'))) {
                return $index;
            }
        }

        throw new RuntimeException('Transaction header row was not found.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parsePeriod(string $period): array
    {
        $parts = preg_split('/\s+to\s+/i', $period) ?: [];
        if (count($parts) !== 2) {
            throw new RuntimeException("Invalid statement period: {$period}");
        }

        return [Carbon::parse($parts[0])->toDateString(), Carbon::parse($parts[1])->toDateString()];
    }

    protected function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(Date::excelToDateTimeObject((float) $value))->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    protected function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function nullableNumber(mixed $value): ?float
    {
        return $value === null || trim((string) $value) === '' ? null : $this->number($value);
    }

    protected function number(mixed $value): float
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || $text === '-') {
            return 0.0;
        }

        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        $clean = preg_replace('/[^0-9.\-]/', '', $text) ?? '';
        $number = in_array($clean, ['', '-', '.'], true) ? 0.0 : (float) $clean;

        return $negative ? -abs($number) : $number;
    }
}
