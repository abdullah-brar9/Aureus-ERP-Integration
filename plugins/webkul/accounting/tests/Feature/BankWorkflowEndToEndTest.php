<?php

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Enums\ReportCompletenessStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\AccountingReconciliationService;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\DirectCashFlowService;
use Webkul\Accounting\Services\ManualAdjustmentService;
use Webkul\Accounting\Services\ReportCompletenessService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function bankEndToEndWorkbookPath(): string
{
    $path = getenv('ACCOUNTING_WORKBOOK_FIXTURE') ?: 'C:/Users/HP/Downloads/Copy of preview.xlsx';

    if (! is_file($path)) {
        test()->markTestSkipped('Set ACCOUNTING_WORKBOOK_FIXTURE to Copy of preview.xlsx.');
    }

    return $path;
}

function postEndToEndAdjustment(
    Company $company,
    Journal $journal,
    User $reviewer,
    Account $debit,
    Account $credit,
    float $amount,
    string $date,
    string $description,
    string $classification,
    ?string $supportingReference = null,
    ?string $taxTreatment = null,
): ManualAdjustment {
    $adjustment = ManualAdjustment::query()->create([
        'company_id'            => $company->id,
        'journal_id'            => $journal->id,
        'debit_account_id'      => $debit->id,
        'credit_account_id'     => $credit->id,
        'date'                  => $date,
        'amount'                => $amount,
        'description'           => $description,
        'supporting_reference'  => $supportingReference,
        'tax_treatment'         => $taxTreatment,
        'source_classification' => $classification,
        'cash_flow_category'    => CashFlowCategory::NonCash->value,
        'approval_status'       => ManualAdjustmentStatus::Draft,
    ]);

    $service = app(ManualAdjustmentService::class);
    $service->approve($adjustment, $reviewer);
    $service->post($adjustment->fresh(), $reviewer);

    return $adjustment->fresh();
}

it('reconciles the complete workbook through bank-only, opening, and final accrual stages', function (): void {
    $path = bankEndToEndWorkbookPath();
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);

    $source = (new CoaSheetReader)->readWithSource($path);
    $map = (new CoaHeaderDetector)->detect($source['rows']);
    $coaRows = (new CoaSheetParser)->parse($source['rows'], $map);
    app(CoaImportService::class)->import(
        rows: $coaRows,
        company: $company,
        mode: 'structure_only',
        currencyId: $currency->id,
        filename: basename($path),
        sourceSheet: $source['sheet_name'],
        fileHash: hash_file('sha256', $path),
        headerRowNumber: $map->headerRowIndex + 1,
        sourceHeaders: $coaRows[0]->sourceHeaders,
        metadataRows: array_slice($source['rows'], 0, $map->headerRowIndex),
    );

    $accounts = Account::query()
        ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
        ->whereNotNull('code')
        ->get()
        ->keyBy('code');
    $account = fn (string $code): Account => $accounts->get($code)
        ?? throw new RuntimeException("Workbook GL {$code} was not imported.");

    $journal = function (string $code, string $name, JournalType $type, Account $default) use ($company, $currency, $user): Journal {
        return Journal::factory()->create([
            'company_id'         => $company->id,
            'currency_id'        => $currency->id,
            'creator_id'         => $user->id,
            'default_account_id' => $default->id,
            'code'               => $code,
            'name'               => $name,
            'type'               => $type,
        ]);
    };
    $hblJournal = $journal('EH'.$company->id, 'HBL workbook', JournalType::BANK, $account('1029'));
    $meezanJournal = $journal('EM'.$company->id, 'Meezan workbook', JournalType::BANK, $account('1030'));
    $generalJournal = $journal('EG'.$company->id, 'Workbook adjustments', JournalType::GENERAL, $account('1018'));

    $import = app(BankStatementImportService::class);
    $hbl = $import->import($path, $company, $hblJournal, $account('1029'), $currency, 'hbl');
    $meezan = $import->import($path, $company, $meezanJournal, $account('1030'), $currency, 'meezan');
    expect($hbl->lines)->toHaveCount(34)
        ->and($meezan->lines)->toHaveCount(25);

    $transferService = app(BankTransferMatchingService::class);
    $matches = $transferService->detect($company->id);
    expect(collect($matches)->pluck('match_reference')->sort()->values()->all())
        ->toBe(['TRF-0707', 'TRF-0721']);

    $spreadsheet = IOFactory::load($path);
    $mappingSheet = $spreadsheet->getSheetByName('Transaction Mapping');
    $mappingService = app(BankMappingService::class);
    $journalService = app(BankJournalService::class);

    for ($row = 3; $row <= $mappingSheet->getHighestDataRow(); $row++) {
        $reference = (string) $mappingSheet->getCell("E{$row}")->getCalculatedValue();
        $mapping = BankTransactionMapping::query()
            ->where('company_id', $company->id)
            ->whereHas('statementLine', fn ($query) => $query->where('reference', $reference))
            ->with(['statementLine', 'transferMatch'])
            ->firstOrFail();
        $cashFlowCategory = (string) $mappingSheet->getCell("T{$row}")->getCalculatedValue();
        expect(CashFlowCategory::tryFrom($cashFlowCategory))->not->toBeNull();

        $mapping->update([
            'map_reference'       => (string) $mappingSheet->getCell("A{$row}")->getCalculatedValue(),
            'offset_account_id'   => $account((string) $mappingSheet->getCell("M{$row}")->getCalculatedValue())->id,
            'transaction_type'    => (string) $mappingSheet->getCell("H{$row}")->getCalculatedValue(),
            'counterparty'        => (string) $mappingSheet->getCell("I{$row}")->getCalculatedValue(),
            'supporting_document' => (string) $mappingSheet->getCell("J{$row}")->getCalculatedValue(),
            'tax_treatment'       => (string) $mappingSheet->getCell("O{$row}")->getCalculatedValue(),
            'cash_flow_category'  => $cashFlowCategory,
        ]);

        $transferReference = (string) $mappingSheet->getCell("R{$row}")->getCalculatedValue();
        if ($transferReference !== '') {
            expect($mapping->fresh()->transferMatch?->match_reference)->toBe($transferReference);

            continue;
        }

        $mapping = $mappingService->approve($mapping->fresh(), $user);
        $journalService->post($mapping, $user);
    }

    foreach ($matches as $match) {
        $transferService->approve($match, $user);
        $outgoing = BankTransactionMapping::query()
            ->where('statement_line_id', $match->outgoing_statement_line_id)
            ->firstOrFail();
        $journalService->post($outgoing, $user);
    }

    $cashFlow = app(DirectCashFlowService::class)->calculate($company->id, '2025-07-01', '2025-07-31');
    expect($cashFlow['categories'][CashFlowCategory::OperatingReceipts->value])->toBe(4240150.0)
        ->and($cashFlow['categories'][CashFlowCategory::OperatingPayments->value])->toBe(-3713312.0)
        ->and($cashFlow['categories'][CashFlowCategory::InvestingPayments->value])->toBe(-200000.0)
        ->and($cashFlow['categories'][CashFlowCategory::Transfer->value])->toBe(0.0)
        ->and($cashFlow['opening_cash'])->toBe(0.0)
        ->and($cashFlow['statement_opening_cash'])->toBe(3730000.0)
        ->and($cashFlow['net_change'])->toBe(326838.0)
        ->and($cashFlow['ending_cash'])->toBe(326838.0)
        ->and($cashFlow['ledger_cash'])->toBe(326838.0)
        ->and($cashFlow['difference'])->toBe(0.0)
        ->and(app(ReportCompletenessService::class)->assess($company->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::MissingOpeningBalances);

    postEndToEndAdjustment($company, $generalJournal, $user, $account('1029'), $account('1038'), 3250000, '2025-06-30', 'Opening HBL balance', 'opening_balances');
    postEndToEndAdjustment($company, $generalJournal, $user, $account('1030'), $account('1038'), 480000, '2025-06-30', 'Opening Meezan balance', 'opening_balances');
    postEndToEndAdjustment($company, $generalJournal, $user, $account('1018'), $account('1038'), 2900000, '2025-06-30', 'Opening trade debtors retained earnings', 'opening_balances');
    postEndToEndAdjustment($company, $generalJournal, $user, $account('1018'), $account('1034'), 1100000, '2025-06-30', 'Opening trade debtors accrued payroll', 'opening_balances');

    $cashFlowAfterOpening = app(DirectCashFlowService::class)->calculate($company->id, '2025-07-01', '2025-07-31');
    expect($cashFlowAfterOpening['ledger_cash'])->toBe(4056838.0)
        ->and($cashFlowAfterOpening['opening_cash'])->toBe(3730000.0)
        ->and($cashFlowAfterOpening['ending_cash'])->toBe(4056838.0)
        ->and($cashFlowAfterOpening['difference'])->toBe(0.0)
        ->and(app(ReportCompletenessService::class)->assess($company->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::MissingNonBankAdjustments);

    $classificationByEntry = [
        'NB-001' => 'unpaid_invoice',
        'NB-002' => 'unpaid_bill',
        'NB-003' => 'accrual',
        'NB-004' => 'amortization',
        'NB-005' => 'depreciation',
        'NB-006' => 'payroll_accrual',
        'NB-007' => 'tax',
    ];
    $adjustmentSheet = $spreadsheet->getSheetByName('Non-Bank Entries');
    for ($row = 3; $row <= $adjustmentSheet->getHighestDataRow(); $row++) {
        $entryId = (string) $adjustmentSheet->getCell("A{$row}")->getCalculatedValue();
        $date = SpreadsheetDate::excelToDateTimeObject((float) $adjustmentSheet->getCell("B{$row}")->getCalculatedValue())->format('Y-m-d');
        postEndToEndAdjustment(
            $company,
            $generalJournal,
            $user,
            $account((string) $adjustmentSheet->getCell("D{$row}")->getCalculatedValue()),
            $account((string) $adjustmentSheet->getCell("F{$row}")->getCalculatedValue()),
            (float) $adjustmentSheet->getCell("H{$row}")->getCalculatedValue(),
            $date,
            (string) $adjustmentSheet->getCell("C{$row}")->getCalculatedValue(),
            $classificationByEntry[$entryId],
            (string) $adjustmentSheet->getCell("K{$row}")->getCalculatedValue(),
            (string) $adjustmentSheet->getCell("I{$row}")->getCalculatedValue(),
        );
    }

    $trialBalance = app(TrialBalanceService::class)->compute($company->id, '2025-07-01', '2025-07-31');
    expect($trialBalance['totals']['opening_debit'])->toBe(7730000.0)
        ->and($trialBalance['totals']['opening_credit'])->toBe(7730000.0)
        ->and($trialBalance['totals']['adjustment_debit'])->toBe(1890000.0)
        ->and($trialBalance['totals']['adjustment_credit'])->toBe(1890000.0)
        ->and($trialBalance['totals']['movement_debit'] + $trialBalance['totals']['adjustment_debit'])->toBe(11443462.0)
        ->and($trialBalance['totals']['movement_credit'] + $trialBalance['totals']['adjustment_credit'])->toBe(11443462.0)
        ->and($trialBalance['totals']['closing_debit'])->toBe(8812800.0)
        ->and($trialBalance['totals']['closing_credit'])->toBe(8812800.0)
        ->and($trialBalance['totals']['difference'])->toBe(0.0)
        ->and(app(ReportCompletenessService::class)->assess($company->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::Complete);

    $profitAndLossNet = function (array $types) use ($company): float {
        return (float) DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->join('accounts_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('moves.company_id', $company->id)
            ->where('moves.state', 'posted')
            ->whereBetween('moves.date', ['2025-07-01', '2025-07-31'])
            ->whereIn('accounts.account_type', $types)
            ->sum('lines.balance');
    };
    $netIncome = -$profitAndLossNet(array_keys(AccountType::income()))
        - $profitAndLossNet(array_keys(AccountType::expenses()));
    expect($netIncome)->toBe(-2178094.0);

    $checks = app(AccountingReconciliationService::class)->checks($company->id, '2025-07-01', '2025-07-31');
    expect(collect($checks)->where('status', 'FAIL')->values()->all())->toBe([]);
});
