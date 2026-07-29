<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ledger-backed Trial Balance export. Values come from the same computed rows
 * the screen renders, so Excel, PDF and screen totals are identical.
 */
class TrialBalanceExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, float>  $totals
     */
    public function __construct(
        protected array $rows,
        protected array $totals,
        protected ?string $company,
        protected string $from,
        protected string $to,
    ) {}

    public function title(): string
    {
        return 'Trial Balance';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $grid = [
            ['Trial Balance — '.($this->company ?? '')],
            [$this->from.' to '.$this->to.' (posted ledger lines)'],
            [],
            ['Code', 'Account', 'Opening Debit', 'Opening Credit', 'Movement Debit', 'Movement Credit', 'Adjustment Debit', 'Adjustment Credit', 'Closing Debit', 'Closing Credit'],
        ];

        $cols = ['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'adjustment_debit', 'adjustment_credit', 'closing_debit', 'closing_credit'];

        foreach ($this->rows as $row) {
            $line = [$row['code'], $row['name']];
            foreach ($cols as $c) {
                $line[] = round((float) $row[$c], 2);
            }
            $grid[] = $line;
        }

        $totalLine = ['', 'Total'];
        foreach ($cols as $c) {
            $totalLine[] = round((float) ($this->totals[$c] ?? 0), 2);
        }
        $grid[] = $totalLine;
        $grid[] = ['', 'Difference', '', '', '', '', '', '', '', round((float) ($this->totals['difference'] ?? 0), 2)];

        return $grid;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1                           => ['font' => ['bold' => true, 'size' => 14]],
            4                           => ['font' => ['bold' => true]],
            $sheet->getHighestRow() - 1 => ['font' => ['bold' => true]],
        ];
    }
}
