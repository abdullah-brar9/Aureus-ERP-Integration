<?php

namespace Webkul\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Enums\CurrencyMode;
use Webkul\Accounting\Enums\EntityMode;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Support\Models\Company;

/**
 * Imports the "Accounts 2025 Format" workbook structure verbatim.
 *
 * Every worksheet becomes a ReportTemplate; every row becomes a ReportLine in
 * workbook order — captions, blank rows, bold flags, hierarchy, subtotals,
 * check rows and column layouts are preserved exactly as they appear in the
 * file. Nothing is renamed, merged or simplified.
 *
 * What is intentionally NOT imported (the workbook is format-only — it holds
 * no live formulas, mappings or data):
 *
 *   - Account bindings. Ledger lines are created UNMAPPED; Finance binds
 *     accounts in the Report Designer (see the Mapping Review page and
 *     STAGE4_UNMAPPED_ACCOUNTS.md). The importer never guesses a mapping.
 *   - Financial logic beyond arithmetic structure. Subtotal formulas are the
 *     inferred arithmetic chains implied by the row layout (e.g. GP = Revenue
 *     - Total Direct Cost); every one is listed for sign-off in
 *     STAGE4_UNRESOLVED_FORMULAS.md.
 *   - Entity columns are matched to companies by name where an unambiguous
 *     match exists; otherwise the column is left unscoped and reported.
 *
 * Idempotent: a sheet whose template code already exists is skipped.
 */
class ReportWorkbookSeeder extends Seeder
{
    /**
     * Company matchers used to resolve entity columns/lines: 'exact' compares
     * the whole name case-insensitively, 'like' requires the phrase to appear
     * in the name. Deliberately narrow — an unresolved entity stays null and
     * is reported rather than guessed.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    protected array $companyMatchers = [
        'tin'   => ['exact' => ['tin', 'truck it in'], 'like' => ['truck it in']],
        'rider' => ['exact' => ['rider'], 'like' => ['rider']],
        'op'    => ['exact' => ['op', 'openport'], 'like' => ['openport', 'open port']],
    ];

    /**
     * @var array<string, ?int>
     */
    protected array $resolvedCompanies = [];

    public function run(): void
    {
        $this->resolveCompanies();

        foreach ($this->sheets() as $sheet) {
            if (ReportTemplate::query()->where('code', $sheet['code'])->exists()) {
                $this->command?->warn("[workbook] {$sheet['name']}: already imported, skipping.");

                continue;
            }

            $this->importSheet($sheet);
        }
    }

    protected function resolveCompanies(): void
    {
        foreach ($this->companyMatchers as $key => $matchers) {
            $this->resolvedCompanies[$key] = null;

            foreach ($matchers['exact'] as $name) {
                $id = Company::query()->whereRaw('LOWER(name) = ?', [$name])->value('id');

                if ($id !== null) {
                    $this->resolvedCompanies[$key] = (int) $id;

                    break;
                }
            }

            if ($this->resolvedCompanies[$key] === null) {
                foreach ($matchers['like'] as $phrase) {
                    $id = Company::query()->whereRaw('LOWER(name) LIKE ?', ['%'.$phrase.'%'])->value('id');

                    if ($id !== null) {
                        $this->resolvedCompanies[$key] = (int) $id;

                        break;
                    }
                }
            }

            if ($this->resolvedCompanies[$key] === null) {
                $this->command?->warn("[workbook] No company matched entity '{$key}' — related columns imported without a company scope (see finance review).");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    protected function importSheet(array $sheet): void
    {
        $template = ReportTemplate::query()->create([
            'name'          => $sheet['name'],
            'code'          => $sheet['code'],
            'layout_type'   => $sheet['layout'],
            'currency_mode' => $sheet['currency'],
            'entity_mode'   => $sheet['entity_mode'],
            'status'        => TemplateStatus::DRAFT,
            'version'       => 1,
            'description'   => $sheet['description'],
        ]);

        foreach ($sheet['columns'] ?? [] as $column) {
            ReportColumn::query()->create([
                'report_template_id' => $template->id,
                'column_type'        => $column['type'],
                'label'              => $column['label'] ?? null,
                'start_month'        => $column['start'] ?? null,
                'end_month'          => $column['end'] ?? null,
                'year_offset'        => $column['year_offset'] ?? 0,
                'company_id'         => isset($column['company']) ? $this->resolvedCompanies[$column['company']] : null,
                'is_consolidated'    => $column['consolidated'] ?? false,
            ]);
        }

        /** @var array<string, ReportLine> $byCode */
        $byCode = [];

        foreach ($sheet['lines'] as $row) {
            $line = ReportLine::query()->create([
                'report_template_id' => $template->id,
                'line_type'          => $row['type'],
                'caption'            => $row['caption'] ?? null,
                'code'               => $row['code'] ?? null,
                'is_bold'            => $row['bold'] ?? false,
                'is_check'           => $row['check'] ?? false,
                'value_source'       => $row['source'] ?? null,
                'value_basis'        => $row['basis'] ?? null,
                'company_id'         => isset($row['company']) ? $this->resolvedCompanies[$row['company']] : null,
            ]);

            if (! empty($row['code'])) {
                $byCode[$row['code']] = $line;
            }
        }

        // Second pass: hierarchy and formulas, now that every line id exists.
        foreach ($sheet['lines'] as $row) {
            if (empty($row['code'])) {
                continue;
            }

            $line = $byCode[$row['code']];

            if (! empty($row['parent'])) {
                $line->update(['parent_id' => $byCode[$row['parent']]->id]);
            }

            foreach ($row['formula'] ?? [] as $sort => $operand) {
                ReportLineFormula::query()->create([
                    'report_line_id'   => $line->id,
                    'operator'         => $operand['op'],
                    'operand_type'     => isset($operand['const']) ? 'constant' : 'line',
                    'operand_line_id'  => isset($operand['line']) ? $byCode[$operand['line']]->id : null,
                    'operand_constant' => $operand['const'] ?? null,
                    'sort'             => $sort,
                ]);
            }
        }

        $this->command?->info("[workbook] Imported '{$sheet['name']}' — ".count($sheet['lines']).' lines, '.count($sheet['columns'] ?? []).' columns.');
    }

    /**
     * The workbook structure, transcribed cell-by-cell from
     * "Copy of Accounts 2025 Format.xlsx". Captions are verbatim, including
     * spelling ("Ammortization", "liabilties") and dash prefixes.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sheets(): array
    {
        return [
            $this->bsGroup(),
            $this->cashflowGroup(),
            $this->riderShiplinePnl(),
            $this->opPnl(),
            $this->tinPnl(),
            $this->notes(),
        ];
    }

    protected function bsGroup(): array
    {
        $entityColumns = function (int $month) {
            return [
                ['type' => ColumnType::MONTH, 'label' => 'TIN - Final', 'start' => $month, 'company' => 'tin'],
                ['type' => ColumnType::MONTH, 'label' => 'Rider', 'start' => $month, 'company' => 'rider'],
                ['type' => ColumnType::MONTH, 'label' => 'OP', 'start' => $month, 'company' => 'op'],
                ['type' => ColumnType::MONTH, 'label' => 'Consolidated', 'start' => $month, 'consolidated' => true],
            ];
        };

        return [
            'name'        => 'BS Group',
            'code'        => 'bs-group',
            'layout'      => LayoutType::PERIOD_TOTAL,
            'currency'    => CurrencyMode::LEDGER_ONLY,
            'entity_mode' => EntityMode::MULTI_COMPANY_CONSOLIDATED,
            'description' => 'Imported from "Copy of Accounts 2025 Format.xlsx", sheet "BS Group" (workbook title cell A1: "TRUCK IT IN"). '
                .'Point-in-time balances as of end of June and end of December. Account mappings pending Finance review.',
            'columns' => [
                ...$entityColumns(6),
                ['type' => ColumnType::SPACER],
                ...$entityColumns(12),
            ],
            'lines' => [
                ['type' => LineType::SECTION_HEADER, 'caption' => 'ASSETS', 'code' => 'assets', 'bold' => true],
                ['type' => LineType::SECTION_HEADER, 'caption' => 'NON-CURRENT ASSETS', 'code' => 'non-current-assets', 'bold' => true, 'parent' => 'assets'],
                ['type' => LineType::DETAIL, 'caption' => 'Property & Equipment', 'code' => 'property-equipment', 'parent' => 'non-current-assets'],
                ['type' => LineType::DETAIL, 'caption' => 'Long term advances and deposits', 'code' => 'long-term-advances', 'parent' => 'non-current-assets'],
                ['type' => LineType::DETAIL, 'caption' => 'Investment - Openport / SPA Pay', 'code' => 'investment-openport', 'parent' => 'non-current-assets'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SECTION_HEADER, 'caption' => 'CURRENT ASSETS', 'code' => 'current-assets', 'bold' => true, 'parent' => 'assets'],
                ['type' => LineType::DETAIL, 'caption' => 'Trade Debts', 'code' => 'trade-debts', 'parent' => 'current-assets'],
                ['type' => LineType::DETAIL, 'caption' => 'Advances & Deposits', 'code' => 'advances-deposits', 'parent' => 'current-assets'],
                ['type' => LineType::DETAIL, 'caption' => 'Cash & Bank', 'code' => 'cash-bank', 'parent' => 'current-assets'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Total Assets', 'code' => 'total-assets', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'property-equipment'],
                    ['op' => '+', 'line' => 'long-term-advances'],
                    ['op' => '+', 'line' => 'investment-openport'],
                    ['op' => '+', 'line' => 'trade-debts'],
                    ['op' => '+', 'line' => 'advances-deposits'],
                    ['op' => '+', 'line' => 'cash-bank'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::SECTION_HEADER, 'caption' => 'EQUITY AND LIABILITIES', 'code' => 'equity-liabilities', 'bold' => true],
                ['type' => LineType::SECTION_HEADER, 'caption' => 'EQUITY', 'code' => 'equity', 'bold' => true, 'parent' => 'equity-liabilities'],
                ['type' => LineType::DETAIL, 'caption' => 'Un-appropriated (Loss)', 'code' => 'unappropriated-loss', 'parent' => 'equity'],
                ['type' => LineType::DETAIL, 'caption' => 'Issued, subscribed and paid-up capital', 'code' => 'paid-up-capital', 'parent' => 'equity'],
                ['type' => LineType::DETAIL, 'caption' => 'Share premium', 'code' => 'share-premium', 'parent' => 'equity'],
                ['type' => LineType::DETAIL, 'caption' => 'Share Deposit Money', 'code' => 'share-deposit-money', 'parent' => 'equity'],
                ['type' => LineType::DETAIL, 'caption' => 'FX Gain / Loss', 'code' => 'fx-gain-loss', 'parent' => 'equity'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SECTION_HEADER, 'caption' => 'CURRENT LIABILITIES', 'code' => 'current-liabilities', 'bold' => true, 'parent' => 'equity-liabilities'],
                ['type' => LineType::DETAIL, 'caption' => 'Creditors, Accrued & Other Liabilities', 'code' => 'creditors-accrued', 'parent' => 'current-liabilities'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Total Equity and Liabilities', 'code' => 'total-equity-liabilities', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'unappropriated-loss'],
                    ['op' => '+', 'line' => 'paid-up-capital'],
                    ['op' => '+', 'line' => 'share-premium'],
                    ['op' => '+', 'line' => 'share-deposit-money'],
                    ['op' => '+', 'line' => 'fx-gain-loss'],
                    ['op' => '+', 'line' => 'creditors-accrued'],
                ]],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Check', 'code' => 'check', 'check' => true, 'formula' => [
                    ['op' => '+', 'line' => 'total-assets'],
                    ['op' => '-', 'line' => 'total-equity-liabilities'],
                ]],
            ],
        ];
    }

    protected function cashflowGroup(): array
    {
        $entityColumns = function (int $startMonth, int $endMonth, array $labels) {
            return [
                ['type' => ColumnType::RANGE, 'label' => $labels[0], 'start' => $startMonth, 'end' => $endMonth, 'company' => 'tin'],
                ['type' => ColumnType::RANGE, 'label' => $labels[1], 'start' => $startMonth, 'end' => $endMonth, 'company' => 'rider'],
                ['type' => ColumnType::RANGE, 'label' => $labels[2], 'start' => $startMonth, 'end' => $endMonth, 'company' => 'op'],
                ['type' => ColumnType::RANGE, 'label' => $labels[3], 'start' => $startMonth, 'end' => $endMonth, 'consolidated' => true],
            ];
        };

        return [
            'name'        => 'Cashflow Group',
            'code'        => 'cashflow-group',
            'layout'      => LayoutType::PERIOD_TOTAL,
            'currency'    => CurrencyMode::USD_ONLY,
            'entity_mode' => EntityMode::MULTI_COMPANY_CONSOLIDATED,
            'description' => 'Imported from "Copy of Accounts 2025 Format.xlsx", sheet "Cashflow Group". '
                .'"June" columns cover Jan-Jun, "Dec" columns Jul-Dec — UNCONFIRMED half-year split, see STAGE4_UNRESOLVED_FORMULAS.md item U1. '
                .'Account mappings and sign conventions pending Finance review.',
            'columns' => [
                ...$entityColumns(1, 6, ['TIN USD', 'Rider/TPL USD', 'Openport USD', 'TIN Cons (USD)']),
                ['type' => ColumnType::SPACER],
                ...$entityColumns(7, 12, ['TIN USD', 'Rider USD', 'Openport USD', 'TIN Cons (USD)']),
            ],
            'lines' => [
                ['type' => LineType::SECTION_HEADER, 'caption' => 'Cash Flow Statement USD (Consolidated)', 'code' => 'cf-title'],
                ['type' => LineType::DETAIL, 'caption' => 'Beginning cash & cash equivalents', 'code' => 'beginning-cash', 'basis' => ValueBasis::OPENING_BALANCE],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Net income w/ FX', 'code' => 'net-income-fx', 'basis' => ValueBasis::MOVEMENT],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Working capital changes', 'code' => 'working-capital', 'formula' => [
                    ['op' => '+', 'line' => 'wc-current-assets'],
                    ['op' => '+', 'line' => 'wc-current-liabilities'],
                ]],
                ['type' => LineType::SUBTOTAL, 'caption' => '- (Increase) / decrease in current assets', 'code' => 'wc-current-assets', 'parent' => 'working-capital', 'formula' => [
                    ['op' => '+', 'line' => 'wc-receivables'],
                    ['op' => '+', 'line' => 'wc-advances'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => '-- Receivables', 'code' => 'wc-receivables', 'parent' => 'wc-current-assets', 'basis' => ValueBasis::MOVEMENT],
                ['type' => LineType::DETAIL, 'caption' => '-- Advances', 'code' => 'wc-advances', 'parent' => 'wc-current-assets', 'basis' => ValueBasis::MOVEMENT],
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => '- Increase / (decrease) in current liabilties', 'code' => 'wc-current-liabilities', 'parent' => 'working-capital', 'formula' => [
                    ['op' => '+', 'line' => 'wc-creditors'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => '-- Creditors and other liabilties', 'code' => 'wc-creditors', 'parent' => 'wc-current-liabilities', 'basis' => ValueBasis::MOVEMENT],
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Cash from Operations', 'code' => 'cash-from-operations', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'net-income-fx'],
                    ['op' => '+', 'line' => 'working-capital'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Capex', 'code' => 'capex', 'basis' => ValueBasis::MOVEMENT],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Cash from Investing', 'code' => 'cash-from-investing', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'capex'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Advance against issue of share capital', 'code' => 'advance-share-capital', 'basis' => ValueBasis::MOVEMENT],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Cash from Financing', 'code' => 'cash-from-financing', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'advance-share-capital'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Net change', 'code' => 'net-change', 'formula' => [
                    ['op' => '+', 'line' => 'cash-from-operations'],
                    ['op' => '+', 'line' => 'cash-from-investing'],
                    ['op' => '+', 'line' => 'cash-from-financing'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Ending cash & cash equivalents', 'code' => 'ending-cash', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'beginning-cash'],
                    ['op' => '+', 'line' => 'net-change'],
                ]],
            ],
        ];
    }

    protected function riderShiplinePnl(): array
    {
        return [
            'name'        => 'RiderShipline PNL',
            'code'        => 'ridershipline-pnl',
            'layout'      => LayoutType::MONTHLY_MATRIX,
            'currency'    => CurrencyMode::USD_ONLY,
            'entity_mode' => EntityMode::SINGLE_COMPANY,
            'description' => 'Imported from "Copy of Accounts 2025 Format.xlsx", sheet "RiderShipline PNL" (workbook heading cell A2: "Rider/Shipline"). '
                .'Monthly matrix Jan-Dec plus Total. Account mappings pending Finance review.',
            'columns' => [],
            'lines'   => [
                ['type' => LineType::DETAIL, 'caption' => 'No. of Parcels', 'code' => 'parcels', 'bold' => true, 'source' => ValueSource::MANUAL],
                ['type' => LineType::SUBTOTAL, 'caption' => 'RPS', 'code' => 'rps', 'formula' => [
                    ['op' => '+', 'line' => 'revenue'],
                    ['op' => '/', 'line' => 'parcels'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Revenue', 'code' => 'revenue', 'bold' => true],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Delivery Cost', 'code' => 'delivery-cost'],
                ['type' => LineType::DETAIL, 'caption' => 'Pickup Cost', 'code' => 'pickup-cost'],
                ['type' => LineType::DETAIL, 'caption' => 'Transportation', 'code' => 'transportation'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Total Direct Cost', 'code' => 'total-direct-cost', 'formula' => [
                    ['op' => '+', 'line' => 'delivery-cost'],
                    ['op' => '+', 'line' => 'pickup-cost'],
                    ['op' => '+', 'line' => 'transportation'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'GP', 'code' => 'gp', 'formula' => [
                    ['op' => '+', 'line' => 'revenue'],
                    ['op' => '-', 'line' => 'total-direct-cost'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Salaries & Benefits', 'code' => 'salaries-benefits'],
                ['type' => LineType::DETAIL, 'caption' => 'Utilities', 'code' => 'utilities'],
                ['type' => LineType::DETAIL, 'caption' => 'Other Cost', 'code' => 'other-cost'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Ebitda', 'code' => 'ebitda', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'gp'],
                    ['op' => '-', 'line' => 'salaries-benefits'],
                    ['op' => '-', 'line' => 'utilities'],
                    ['op' => '-', 'line' => 'other-cost'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Depreciation', 'code' => 'depreciation'],
                ['type' => LineType::DETAIL, 'caption' => 'Ammortization', 'code' => 'ammortization'],
                ['type' => LineType::DETAIL, 'caption' => 'Interest Expense', 'code' => 'interest-expense'],
                ['type' => LineType::DETAIL, 'caption' => 'Income Tax', 'code' => 'income-tax', 'bold' => true],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'NI', 'code' => 'ni', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'ebitda'],
                    ['op' => '-', 'line' => 'depreciation'],
                    ['op' => '-', 'line' => 'ammortization'],
                    ['op' => '-', 'line' => 'interest-expense'],
                    ['op' => '-', 'line' => 'income-tax'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'USD Rate', 'code' => 'usd-rate', 'source' => ValueSource::MANUAL],
            ],
        ];
    }

    protected function opPnl(): array
    {
        return [
            'name'        => 'OP PNL',
            'code'        => 'op-pnl',
            'layout'      => LayoutType::MONTHLY_MATRIX,
            'currency'    => CurrencyMode::USD_ONLY,
            'entity_mode' => EntityMode::SINGLE_COMPANY,
            'description' => 'Imported from "Copy of Accounts 2025 Format.xlsx", sheet "OP PNL" (workbook heading cell A2: "OpenPort"). '
                .'Monthly matrix Jan-Dec plus Total. Account mappings pending Finance review.',
            'columns' => [],
            'lines'   => [
                ['type' => LineType::DETAIL, 'caption' => 'Volume', 'code' => 'volume', 'bold' => true, 'source' => ValueSource::MANUAL],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Revenue', 'code' => 'revenue', 'bold' => true],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Total Direct Cost', 'code' => 'total-direct-cost', 'bold' => true],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'GP', 'code' => 'gp', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'revenue'],
                    ['op' => '-', 'line' => 'total-direct-cost'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Salaries & Benefits', 'code' => 'salaries-benefits'],
                ['type' => LineType::DETAIL, 'caption' => 'Utilities', 'code' => 'utilities'],
                ['type' => LineType::DETAIL, 'caption' => 'Other Cost', 'code' => 'other-cost'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Ebitda', 'code' => 'ebitda', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'gp'],
                    ['op' => '-', 'line' => 'salaries-benefits'],
                    ['op' => '-', 'line' => 'utilities'],
                    ['op' => '-', 'line' => 'other-cost'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'Depreciation', 'code' => 'depreciation'],
                ['type' => LineType::DETAIL, 'caption' => 'Income Tax', 'code' => 'income-tax'],
                ['type' => LineType::SPACER],
                ['type' => LineType::SUBTOTAL, 'caption' => 'NI', 'code' => 'ni', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'ebitda'],
                    ['op' => '-', 'line' => 'depreciation'],
                    ['op' => '-', 'line' => 'income-tax'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'USD Rate', 'code' => 'usd-rate', 'source' => ValueSource::MANUAL],
            ],
        ];
    }

    protected function tinPnl(): array
    {
        return [
            'name'        => 'TIN PNL',
            'code'        => 'tin-pnl',
            'layout'      => LayoutType::MONTHLY_MATRIX,
            'currency'    => CurrencyMode::USD_ONLY,
            'entity_mode' => EntityMode::SINGLE_COMPANY,
            'description' => 'Imported from "Copy of Accounts 2025 Format.xlsx", sheet "TIN PNL" (workbook heading cell A2: "Truck It In"). '
                .'Monthly matrix Jan-Dec plus Total. "OpenPort NI" / "Rider NI" are cross-entity lines pending company + account mapping. '
                .'Account mappings pending Finance review.',
            'columns' => [],
            'lines'   => [
                ['type' => LineType::DETAIL, 'caption' => 'GMV', 'code' => 'gmv', 'bold' => true],
                ['type' => LineType::DETAIL, 'caption' => 'GST', 'code' => 'gst'],
                ['type' => LineType::DETAIL, 'caption' => "Trucker's Commission", 'code' => 'truckers-commission'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'GM', 'code' => 'gm', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'gmv'],
                    ['op' => '-', 'line' => 'gst'],
                    ['op' => '-', 'line' => 'truckers-commission'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'Fleet Subsidy', 'code' => 'fleet-subsidy'],
                ['type' => LineType::DETAIL, 'caption' => 'Customer Subsidy', 'code' => 'customer-subsidy'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'NR', 'code' => 'nr', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'gm'],
                    ['op' => '-', 'line' => 'fleet-subsidy'],
                    ['op' => '-', 'line' => 'customer-subsidy'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'Offline Mktg & Channels', 'code' => 'offline-mktg'],
                ['type' => LineType::DETAIL, 'caption' => 'Digital Mktg.', 'code' => 'digital-mktg'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'CM1', 'code' => 'cm1', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'nr'],
                    ['op' => '-', 'line' => 'offline-mktg'],
                    ['op' => '-', 'line' => 'digital-mktg'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'Financial Charges', 'code' => 'financial-charges'],
                ['type' => LineType::DETAIL, 'caption' => 'Tech', 'code' => 'tech'],
                ['type' => LineType::DETAIL, 'caption' => 'Call Center & Support', 'code' => 'call-center'],
                ['type' => LineType::DETAIL, 'caption' => 'Returns & Waivers', 'code' => 'returns-waivers'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'CM 2', 'code' => 'cm2', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'cm1'],
                    ['op' => '-', 'line' => 'financial-charges'],
                    ['op' => '-', 'line' => 'tech'],
                    ['op' => '-', 'line' => 'call-center'],
                    ['op' => '-', 'line' => 'returns-waivers'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'People', 'code' => 'people'],
                ['type' => LineType::DETAIL, 'caption' => 'Real Estate', 'code' => 'real-estate'],
                ['type' => LineType::DETAIL, 'caption' => 'Travel & Entertainment', 'code' => 'travel-entertainment'],
                ['type' => LineType::DETAIL, 'caption' => 'Professional Services', 'code' => 'professional-services'],
                ['type' => LineType::DETAIL, 'caption' => 'Misc.', 'code' => 'misc'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'EBITDA', 'code' => 'ebitda', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'cm2'],
                    ['op' => '-', 'line' => 'people'],
                    ['op' => '-', 'line' => 'real-estate'],
                    ['op' => '-', 'line' => 'travel-entertainment'],
                    ['op' => '-', 'line' => 'professional-services'],
                    ['op' => '-', 'line' => 'misc'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'Depreciation', 'code' => 'depreciation'],
                ['type' => LineType::DETAIL, 'caption' => 'Ammortization', 'code' => 'ammortization'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'EBIT', 'code' => 'ebit', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'ebitda'],
                    ['op' => '-', 'line' => 'depreciation'],
                    ['op' => '-', 'line' => 'ammortization'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'Interest', 'code' => 'interest'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'EBTDA', 'code' => 'ebtda', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'ebit'],
                    ['op' => '-', 'line' => 'interest'],
                ]],
                ['type' => LineType::DETAIL, 'caption' => 'Income Tax', 'code' => 'income-tax'],
                ['type' => LineType::DETAIL, 'caption' => 'Cost of Compliance', 'code' => 'cost-of-compliance'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'TIN NI', 'code' => 'tin-ni', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'ebtda'],
                    ['op' => '-', 'line' => 'income-tax'],
                    ['op' => '-', 'line' => 'cost-of-compliance'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'OpenPort NI', 'code' => 'openport-ni', 'company' => 'op'],
                ['type' => LineType::DETAIL, 'caption' => 'Rider NI', 'code' => 'rider-ni', 'company' => 'rider'],
                ['type' => LineType::SUBTOTAL, 'caption' => 'Total NI', 'code' => 'total-ni', 'bold' => true, 'formula' => [
                    ['op' => '+', 'line' => 'tin-ni'],
                    ['op' => '+', 'line' => 'openport-ni'],
                    ['op' => '+', 'line' => 'rider-ni'],
                ]],
                ['type' => LineType::SPACER],
                ['type' => LineType::DETAIL, 'caption' => 'USD Rate', 'code' => 'usd-rate', 'source' => ValueSource::MANUAL],
            ],
        ];
    }

    protected function notes(): array
    {
        $header = fn (string $caption, bool $bold = false) => [
            'type' => LineType::SECTION_HEADER, 'caption' => $caption, 'bold' => $bold,
        ];

        return [
            'name'        => 'Notes',
            'code'        => 'notes',
            'layout'      => LayoutType::PERIOD_TOTAL,
            'currency'    => CurrencyMode::LEDGER_ONLY,
            'entity_mode' => EntityMode::SINGLE_COMPANY,
            'description' => 'Imported from "Copy of Accounts 2025 Format.xlsx", sheet "Notes" — a responsibility/reference sheet, not a financial statement. '
                .'Owner assignments (workbook column B) are documented in STAGE4_FINANCE_REVIEW.md.',
            'columns' => [],
            'lines'   => [
                $header('PNL', true),
                $header('Revenue'),
                $header('Sales Tax'),
                $header('Direct Cost - Vendors'),
                ['type' => LineType::SPACER],
                $header('Expenses', true),
                $header('Admin'),
                $header('Professional Services'),
                $header('People'),
                $header('Misc'),
                $header('Other Income'),
                $header('Income Tax'),
                $header('Finance Costs'),
                ['type' => LineType::SPACER],
                $header('BS', true),
                $header('Assets - Current/Fixed'),
                $header('PPE'),
                $header('Advance & deposits'),
                $header('Long Term Adv & Dep'),
                $header('Trade Debts (Receivables)'),
                $header('Taxation'),
                $header('Cash & Bank'),
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                $header('Liab & Equity', true),
                $header('Share Capital'),
                $header('Share Premium'),
                $header('Accumulated Losses/Profit (Retained Earnings)'),
                $header('Advance Against Equity'),
                $header('Accured and other Liab'),
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                ['type' => LineType::SPACER],
                $header('Workings', true),
                ['type' => LineType::SPACER],
                $header('Sales Tax / Income Tax'),
                $header('Bank Reconciliations'),
                $header('Costings - Vertical Wise'),
                $header('Variance Analysis'),
                $header('Sales Tax Reporting - Monthly'),
                $header('Income Tax Reporting - Monthly (Advance Tax)'),
                $header('MIS Reconciliation'),
            ],
        ];
    }
}
