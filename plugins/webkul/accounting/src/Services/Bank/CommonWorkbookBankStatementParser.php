<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;
use Webkul\Accounting\Data\Bank\NormalizedBankTransaction;

/**
 * Parses the common, bank-neutral statement layout used by the financial-model
 * workbooks. Unlike the legacy HBL/Meezan parsers, the bank, GL code and
 * opening balance are label/value metadata rows and the period is derived from
 * the transaction dates.
 */
class CommonWorkbookBankStatementParser extends AbstractSpreadsheetBankStatementParser
{
    public function key(): string
    {
        return 'workbook_common';
    }

    public function supports(string $path, ?string $sheetName = null): bool
    {
        if (! in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            return false;
        }

        $spreadsheet = IOFactory::load($path);

        try {
            if ($sheetName !== null) {
                $sheet = $spreadsheet->getSheetByName($sheetName);

                return $sheet !== null && $this->isCommonStatementSheet($sheet->toArray(null, true, true, false));
            }

            return collect($spreadsheet->getAllSheets())
                ->filter(fn ($sheet): bool => $this->isCommonStatementSheet($sheet->toArray(null, true, true, false)))
                ->count() === 1;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function parse(string $path, ?string $sheetName = null): NormalizedBankStatement
    {
        $sheetName ??= $this->singleCommonSheetName($path);
        [$rows, $resolvedSheet] = $this->rows($path, $sheetName);

        if (! $this->isCommonStatementSheet($rows)) {
            throw new RuntimeException("Worksheet [{$sheetName}] does not use the common bank-statement layout.");
        }

        $headerRow = $this->findTransactionHeader($rows);
        $metadata = $this->metadata(array_slice($rows, 0, $headerRow));
        $bank = trim((string) ($metadata['bank'] ?? ''));
        $bankGlCode = trim((string) ($metadata['bank gl code'] ?? ''));
        $openingBalance = $this->number($metadata['opening balance'] ?? null);

        if ($bank === '' || $bankGlCode === '') {
            throw new RuntimeException('The Bank and Bank GL Code metadata values are required.');
        }

        $transactions = [];
        $totalDebits = BigDecimal::zero();
        $totalCredits = BigDecimal::zero();
        $dates = [];
        $closingBalance = null;

        for ($index = $headerRow + 1; $index < count($rows); $index++) {
            $row = array_values($rows[$index]);
            $transactionDate = $this->date($row[0] ?? null);
            $description = trim((string) ($row[2] ?? ''));

            if ($transactionDate === null && $description === '') {
                continue;
            }

            if ($transactionDate === null) {
                throw new RuntimeException('Every populated transaction row must contain a valid Transaction Date.');
            }

            $debit = $this->number($row[4] ?? null);
            $credit = $this->number($row[5] ?? null);
            $runningBalance = $this->nullableNumber($row[6] ?? null);
            $totalDebits = $totalDebits->plus($debit);
            $totalCredits = $totalCredits->plus($credit);
            $dates[] = $transactionDate;
            $closingBalance = $runningBalance ?? $closingBalance;

            $transactions[] = new NormalizedBankTransaction(
                transactionDate: $transactionDate,
                valueDate: $this->date($row[1] ?? null),
                description: $description,
                reference: $this->nullableString($row[3] ?? null),
                debit: $debit,
                credit: $credit,
                runningBalance: $runningBalance,
                sourceRow: $index + 1,
                rawRow: $row,
            );
        }

        if ($transactions === []) {
            throw new RuntimeException('The selected worksheet contains no bank transactions.');
        }

        sort($dates);
        $closingBalance ??= BigDecimal::of($openingBalance)
            ->plus($totalCredits)
            ->minus($totalDebits)
            ->__toString();

        return new NormalizedBankStatement(
            bank: $bank,
            bankAccountNumber: $bank,
            accountTitle: $bankGlCode,
            currency: $this->currencyFromHeaders($rows[$headerRow]),
            statementStartDate: $dates[0],
            statementEndDate: $dates[array_key_last($dates)],
            openingBalance: $openingBalance,
            totalDebits: $totalDebits->__toString(),
            totalCredits: $totalCredits->__toString(),
            closingBalance: $closingBalance,
            parser: $this->key(),
            sourceSheet: $resolvedSheet,
            rawHeader: array_values(array_slice($rows, 0, $headerRow + 1)),
            transactions: $transactions,
        );
    }

    protected function bankName(): string
    {
        return 'Workbook';
    }

    protected function detectionToken(): string
    {
        return 'Bank';
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function isCommonStatementSheet(array $rows): bool
    {
        try {
            $headerRow = $this->findTransactionHeader($rows);
        } catch (RuntimeException) {
            return false;
        }

        $metadata = $this->metadata(array_slice($rows, 0, $headerRow));

        return filled($metadata['bank'] ?? null)
            && filled($metadata['bank gl code'] ?? null)
            && array_key_exists('opening balance', $metadata);
    }

    private function singleCommonSheetName(string $path): string
    {
        $spreadsheet = IOFactory::load($path);

        try {
            $names = collect($spreadsheet->getAllSheets())
                ->filter(fn ($sheet): bool => $this->isCommonStatementSheet($sheet->toArray(null, true, true, false)))
                ->map(fn ($sheet): string => $sheet->getTitle())
                ->values();

            if ($names->count() !== 1) {
                throw new RuntimeException('Choose a worksheet name when the workbook contains multiple common-format bank statements.');
            }

            return $names->first();
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function metadata(array $rows): array
    {
        $metadata = [];

        foreach ($rows as $row) {
            $label = mb_strtolower(trim((string) ($row[0] ?? '')));
            $label = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $label));

            if ($label !== '') {
                $metadata[$label] = $row[1] ?? null;
            }
        }

        return $metadata;
    }

    /** @param array<int, mixed> $headers */
    private function currencyFromHeaders(array $headers): string
    {
        foreach ($headers as $header) {
            if (preg_match('/\(([A-Z]{3})\)/i', (string) $header, $matches) === 1) {
                return mb_strtoupper($matches[1]);
            }
        }

        return '';
    }
}
