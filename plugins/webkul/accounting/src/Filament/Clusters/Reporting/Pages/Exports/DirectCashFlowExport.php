<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DirectCashFlowExport implements FromArray, WithColumnWidths, WithStyles
{
    public function __construct(
        protected array $data,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function array(): array
    {
        $rows = [
            ['Direct-method Cash Flow Statement', ''],
            ["{$this->dateFrom} to {$this->dateTo}", ''],
            ['Currency mode: '.$this->data['currency_mode'].'; status: '.$this->data['conversion_status'], $this->data['rate_basis']],
            ['', ''],
        ];

        foreach ($this->data['warnings'] as $warning) {
            $rows[] = ['Warning', $warning];
        }

        foreach ($this->data['reports'] as $currency => $report) {
            $rows[] = [$currency, 'Amount'];
            foreach ($report['categories'] as $category => $amount) {
                $rows[] = [$category, (float) $amount];
            }
            $rows[] = ['Net change in cash', (float) $report['net_change']];
            $rows[] = ['Opening cash (posted ledger)', (float) $report['opening_cash']];
            $rows[] = ['Statement opening reference', (float) $report['statement_opening_cash']];
            $rows[] = ['Ending cash', (float) $report['ending_cash']];
            $rows[] = ['Posted bank ledger cash', (float) $report['ledger_cash']];
            $rows[] = ['Cash flow check', (float) $report['difference']];
            $rows[] = ['', ''];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 42, 'B' => 24];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:B1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('B:B')->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00)');

        return [];
    }
}
