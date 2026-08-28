<?php

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\AccountingReconciliationService;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Bank\BankStatementValidationService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Services\Bank\CommonWorkbookBankStatementParser;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\Coa\MigrationJournalService;
use Webkul\Accounting\Services\DirectCashFlowService;
use Webkul\Accounting\Services\ManualAdjustmentService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function tenBankFinancialModelPath(): string
{
    $path = getenv('ACCOUNTING_TEN_BANK_WORKBOOK_FIXTURE');

    if (! is_string($path) || $path === '' || ! is_file($path)) {
        test()->markTestSkipped('Set ACCOUNTING_TEN_BANK_WORKBOOK_FIXTURE to ten_bank_fs_tagged_financial_model.xlsx.');
    }

    return $path;
}

it('parses and reconciles all ten common-format bank sheets from the acceptance model', function (): void {
    $path = tenBankFinancialModelPath();
    $registry = app(BankStatementParserRegistry::class);
    $validator = app(BankStatementValidationService::class);
    $sheetNames = [
        'Bank 01 - HBL Main',
        'Bank 02 - Meezan Payroll',
        'Bank 03 - UBL Collections',
        'Bank 04 - MCB Reserve',
        'Bank 05 - HBL North',
        'Bank 06 - Meezan Corporate',
        'Bank 07 - UBL Operations',
        'Bank 08 - MCB Corporate',
        'Bank 09 - HBL Reserve',
        'Bank 10 - HBL Digital',
    ];

    $transactionCount = 0;
    $openingBalance = 0.0;
    $closingBalance = 0.0;
    $grossMovement = 0.0;
    $fsTagCodes = [];
    $offsetGlCodes = [];

    foreach ($sheetNames as $sheetName) {
        $parser = $registry->resolve($path, null, $sheetName);
        $statement = $parser->parse($path, $sheetName);

        expect($parser)->toBeInstanceOf(CommonWorkbookBankStatementParser::class)
            ->and($statement->sourceSheet)->toBe($sheetName)
            ->and($statement->currency)->toBe('PKR')
            ->and($validator->validate($statement))->toBe([]);

        $transactionCount += count($statement->transactions);
        $openingBalance += (float) $statement->openingBalance;
        $closingBalance += (float) $statement->closingBalance;
        $grossMovement += (float) $statement->totalDebits + (float) $statement->totalCredits;

        foreach ($statement->transactions as $transaction) {
            $offsetGlCodes[] = trim((string) ($transaction->rawRow[9] ?? ''));
            $fsTagCodes[] = trim((string) ($transaction->rawRow[12] ?? ''));
        }
    }

    expect($transactionCount)->toBe(40)
        ->and($openingBalance)->toBe(3800000.0)
        ->and($closingBalance)->toBe(5199000.0)
        ->and($grossMovement)->toBe(7489000.0)
        ->and(collect($offsetGlCodes)->filter()->count())->toBe(40)
        ->and(collect($fsTagCodes)->filter()->count())->toBe(40);

    expect(fn () => $registry->resolve($path, 'workbook_common')->parse($path))
        ->toThrow(RuntimeException::class, 'Choose a worksheet name');
});

it('posts the acceptance model through the canonical ledger and reconciles its closing reports', function (): void {
    $path = tenBankFinancialModelPath();
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);

    $source = (new CoaSheetReader)->readWithSource($path);
    $columnMap = (new CoaHeaderDetector)->detect($source['rows']);
    $coaRows = (new CoaSheetParser)->parse($source['rows'], $columnMap);

    expect($source['sheet_name'])->toBe('Chart of Accounts')
        ->and($columnMap->headerRowIndex + 1)->toBe(5)
        ->and($coaRows)->toHaveCount(62);

    $batch = app(CoaImportService::class)->import(
        rows: $coaRows,
        company: $company,
        mode: 'structure_only',
        currencyId: $currency->id,
        filename: basename($path),
        sourceSheet: $source['sheet_name'],
        fileHash: hash_file('sha256', $path),
        headerRowNumber: $columnMap->headerRowIndex + 1,
        sourceHeaders: $coaRows[0]->sourceHeaders,
        metadataRows: array_slice($source['rows'], 0, $columnMap->headerRowIndex),
    );

    $accounts = Account::query()
        ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
        ->whereNotNull('code')
        ->get()
        ->keyBy('code');
    $account = fn (string $code): Account => $accounts->get($code)
        ?? throw new RuntimeException("Acceptance GL {$code} was not imported.");

    expect($accounts)->toHaveCount(62)
        ->and($account('1028')->account_type)->toBe(AccountType::ASSET_CASH)
        ->and($account('1029')->account_type)->toBe(AccountType::ASSET_CASH)
        ->and($account('1030')->account_type)->toBe(AccountType::ASSET_CASH)
        ->and($account('1031')->account_type)->toBe(AccountType::ASSET_CASH);

    $generalJournal = Journal::factory()->create([
        'company_id'         => $company->id,
        'currency_id'        => $currency->id,
        'creator_id'         => $user->id,
        'default_account_id' => $account('1018')->id,
        'code'               => 'FMG'.$company->id,
        'name'               => 'Financial model general',
        'type'               => JournalType::GENERAL,
    ]);

    $spreadsheet = IOFactory::load($path);
    $openingSheet = $spreadsheet->getSheetByName('Opening Balances');
    $openingByCode = [];
    for ($row = 3; $row <= $openingSheet->getHighestDataRow(); $row++) {
        $openingByCode[(string) $openingSheet->getCell("A{$row}")->getCalculatedValue()] = [
            'debit'  => (float) $openingSheet->getCell("D{$row}")->getCalculatedValue(),
            'credit' => (float) $openingSheet->getCell("E{$row}")->getCalculatedValue(),
        ];
    }
    expect(collect($openingByCode)->sum('debit'))->toBe(7425000.0)
        ->and(collect($openingByCode)->sum('credit'))->toBe(7425000.0);

    $openingRows = array_map(fn (CoaRow $row): CoaRow => new CoaRow(
        sheetRow: $row->sheetRow,
        nature: $row->nature,
        classifications: $row->classifications,
        code: $row->code,
        title: $row->title,
        openingDebit: $openingByCode[$row->code]['debit'] ?? 0,
        openingCredit: $openingByCode[$row->code]['credit'] ?? 0,
        movementDebit: 0,
        movementCredit: 0,
        adjustmentDebit: 0,
        adjustmentCredit: 0,
        closingDebit: 0,
        closingCredit: 0,
        classificationValues: $row->classificationValues,
        rawRow: $row->rawRow,
        rawRowByHeader: $row->rawRowByHeader,
        sourceHeaders: $row->sourceHeaders,
    ), $coaRows);
    expect(app(MigrationJournalService::class)->createForBatch(
        $batch,
        $openingRows,
        $company,
        $currency->id,
        '2025-06-30',
        '2025-07-01',
        '2025-07-31',
    ))->toBe(1);

    $mappingSheet = $spreadsheet->getSheetByName('Transaction Mapping');
    $mappingRows = [];
    for ($row = 3; $row <= $mappingSheet->getHighestDataRow(); $row++) {
        $mappingRows[] = [
            'map_reference'      => (string) $mappingSheet->getCell("A{$row}")->getCalculatedValue(),
            'bank'               => (string) $mappingSheet->getCell("B{$row}")->getCalculatedValue(),
            'description'        => (string) $mappingSheet->getCell("D{$row}")->getCalculatedValue(),
            'reference'          => (string) $mappingSheet->getCell("E{$row}")->getCalculatedValue(),
            'transaction_type'   => (string) $mappingSheet->getCell("H{$row}")->getCalculatedValue(),
            'counterparty'       => (string) $mappingSheet->getCell("I{$row}")->getCalculatedValue(),
            'supporting_document'=> (string) $mappingSheet->getCell("J{$row}")->getCalculatedValue(),
            'bank_gl_code'       => (string) $mappingSheet->getCell("K{$row}")->getCalculatedValue(),
            'offset_gl_code'     => (string) $mappingSheet->getCell("M{$row}")->getCalculatedValue(),
            'tax_treatment'      => (string) $mappingSheet->getCell("O{$row}")->getCalculatedValue(),
            'transfer_reference' => (string) $mappingSheet->getCell("R{$row}")->getCalculatedValue(),
            'cash_flow_category' => (string) $mappingSheet->getCell("T{$row}")->getCalculatedValue(),
            'fs_tag_name'        => (string) $mappingSheet->getCell("U{$row}")->getCalculatedValue(),
            'fs_tag_code'        => (string) $mappingSheet->getCell("V{$row}")->getCalculatedValue(),
        ];
    }

    $tagSheet = $spreadsheet->getSheetByName('FS Tag Registry');
    for ($row = 3; $row <= $tagSheet->getHighestDataRow(); $row++) {
        $tagName = (string) $tagSheet->getCell("A{$row}")->getCalculatedValue();
        $tagCode = (string) $tagSheet->getCell("B{$row}")->getCalculatedValue();
        $mappingRow = collect($mappingRows)->first(
            fn (array $candidate): bool => $candidate['fs_tag_code'] === $tagCode
                && $candidate['transfer_reference'] === '',
        );

        FsTag::query()->create([
            'company_id'        => $company->id,
            'creator_id'        => $user->id,
            'account_id'        => $mappingRow ? $account($mappingRow['offset_gl_code'])->id : null,
            'code'              => $tagCode,
            'name'              => $tagName,
            'cash_flow_category'=> $mappingRow['cash_flow_category'] ?? CashFlowCategory::Transfer->value,
            'tax_treatment'     => $mappingRow['tax_treatment'] ?? null,
            'is_active'         => true,
        ]);
    }

    $journalByCode = [];
    foreach (['1028', '1029', '1030', '1031'] as $code) {
        $journalByCode[$code] = Journal::factory()->create([
            'company_id'         => $company->id,
            'currency_id'        => $currency->id,
            'creator_id'         => $user->id,
            'default_account_id' => $account($code)->id,
            'code'               => 'FMB'.$code.$company->id,
            'name'               => "Financial model bank {$code}",
            'type'               => JournalType::BANK,
        ]);
    }

    $statementSheets = [
        'Bank 01 - HBL Main', 'Bank 02 - Meezan Payroll', 'Bank 03 - UBL Collections',
        'Bank 04 - MCB Reserve', 'Bank 05 - HBL North', 'Bank 06 - Meezan Corporate',
        'Bank 07 - UBL Operations', 'Bank 08 - MCB Corporate', 'Bank 09 - HBL Reserve',
        'Bank 10 - HBL Digital',
    ];
    $importService = app(BankStatementImportService::class);
    foreach ($statementSheets as $sheetName) {
        $metadataSheet = $spreadsheet->getSheetByName($sheetName);
        $bankGlCode = (string) $metadataSheet->getCell('B4')->getCalculatedValue();
        $importService->import(
            path: $path,
            company: $company,
            journal: $journalByCode[$bankGlCode],
            bankGlAccount: $account($bankGlCode),
            currency: $currency,
            parserKey: 'workbook_common',
            sheetName: $sheetName,
        );
    }

    expect(BankTransactionMapping::query()->where('company_id', $company->id)->count())->toBe(40)
        ->and(BankTransactionMapping::query()->where('company_id', $company->id)->whereNull('fs_tag_id')->count())->toBe(0);

    $transferService = app(BankTransferMatchingService::class);
    $matches = $transferService->detect($company->id);
    expect($matches)->toHaveCount(5);

    $mappingService = app(BankMappingService::class);
    $journalService = app(BankJournalService::class);
    foreach ($mappingRows as $row) {
        $mapping = BankTransactionMapping::query()
            ->where('company_id', $company->id)
            ->whereHas('statementLine', fn ($query) => $query->where('reference', $row['reference'])
                ->whereHas('statement', fn ($statementQuery) => $statementQuery->where('bank_name', $row['bank'])))
            ->with(['statementLine', 'transferMatch', 'fsTag'])
            ->firstOrFail();

        $mapping->update([
            'map_reference'       => $row['map_reference'],
            'offset_account_id'   => $account($row['offset_gl_code'])->id,
            'transaction_type'    => $row['transaction_type'],
            'counterparty'        => $row['counterparty'],
            'supporting_document' => $row['supporting_document'],
            'tax_treatment'       => $row['tax_treatment'],
            'cash_flow_category'  => $row['cash_flow_category'],
        ]);

        expect($mapping->fsTag?->code)->toBe($row['fs_tag_code']);

        if ($row['transfer_reference'] !== '') {
            expect($mapping->transfer_match_id)->not->toBeNull();

            continue;
        }

        $approved = $mappingService->approve($mapping->fresh(), $user, false);
        $journalService->post($approved, $user);
    }

    foreach ($matches as $match) {
        $transferService->approve($match, $user);
        $outgoing = BankTransactionMapping::query()
            ->where('statement_line_id', $match->outgoing_statement_line_id)
            ->firstOrFail();

        if ($outgoing->posting_status !== BankPostingStatus::MatchedDoNotPost) {
            $journalService->post($outgoing, $user);
        }
    }

    $nonBankSheet = $spreadsheet->getSheetByName('Non-Bank Entries');
    $debitRow = 3;
    $creditRow = 4;
    $adjustment = ManualAdjustment::query()->create([
        'company_id'             => $company->id,
        'journal_id'             => $generalJournal->id,
        'debit_account_id'       => $account((string) $nonBankSheet->getCell("D{$debitRow}")->getCalculatedValue())->id,
        'credit_account_id'      => $account((string) $nonBankSheet->getCell("D{$creditRow}")->getCalculatedValue())->id,
        'date'                   => SpreadsheetDate::excelToDateTimeObject((float) $nonBankSheet->getCell("B{$debitRow}")->getCalculatedValue())->format('Y-m-d'),
        'amount'                 => (float) $nonBankSheet->getCell("F{$debitRow}")->getCalculatedValue(),
        'description'            => (string) $nonBankSheet->getCell("C{$debitRow}")->getCalculatedValue(),
        'tax_treatment'          => (string) $nonBankSheet->getCell("I{$debitRow}")->getCalculatedValue(),
        'source_classification'  => 'depreciation',
        'cash_flow_category'     => CashFlowCategory::NonCash->value,
        'approval_status'        => ManualAdjustmentStatus::Draft,
    ]);
    app(ManualAdjustmentService::class)->approve($adjustment, $user);
    app(ManualAdjustmentService::class)->post($adjustment->fresh(), $user);
    $spreadsheet->disconnectWorksheets();

    $trialBalance = app(TrialBalanceService::class)->compute($company->id, '2025-07-01', '2025-07-31');
    expect($trialBalance['totals']['opening_debit'])->toBe(7425000.0)
        ->and($trialBalance['totals']['opening_credit'])->toBe(7425000.0)
        ->and($trialBalance['totals']['movement_debit'])->toBe(6389000.0)
        ->and($trialBalance['totals']['movement_credit'])->toBe(6389000.0)
        ->and($trialBalance['totals']['adjustment_debit'])->toBe(50000.0)
        ->and($trialBalance['totals']['adjustment_credit'])->toBe(50000.0)
        ->and($trialBalance['totals']['closing_debit'])->toBe(10522000.0)
        ->and($trialBalance['totals']['closing_credit'])->toBe(10522000.0)
        ->and($trialBalance['totals']['difference'])->toBe(0.0);

    $cashFlow = app(DirectCashFlowService::class)->calculate($company->id, '2025-07-01', '2025-07-31');
    expect($cashFlow['categories'][CashFlowCategory::OperatingReceipts->value])->toBe(2944000.0)
        ->and($cashFlow['categories'][CashFlowCategory::OperatingPayments->value])->toBe(-1775000.0)
        ->and($cashFlow['categories'][CashFlowCategory::InvestingPayments->value])->toBe(-120000.0)
        ->and($cashFlow['categories'][CashFlowCategory::FinancingReceipts->value])->toBe(500000.0)
        ->and($cashFlow['categories'][CashFlowCategory::FinancingPayments->value])->toBe(-150000.0)
        ->and($cashFlow['categories'][CashFlowCategory::Transfer->value])->toBe(0.0)
        ->and($cashFlow['opening_cash'])->toBe(3800000.0)
        ->and($cashFlow['ending_cash'])->toBe(5199000.0)
        ->and($cashFlow['ledger_cash'])->toBe(5199000.0)
        ->and($cashFlow['difference'])->toBe(0.0);

    $periodTotals = DB::table('accounts_account_move_lines as lines')
        ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
        ->where('moves.company_id', $company->id)
        ->where('moves.state', 'posted')
        ->whereBetween('moves.date', ['2025-07-01', '2025-07-31'])
        ->selectRaw('SUM(lines.debit) debit, SUM(lines.credit) credit')
        ->first();
    expect((float) $periodTotals->debit)->toBe(6439000.0)
        ->and((float) $periodTotals->credit)->toBe(6439000.0)
        ->and(DB::table('accounts_account_moves')->where('company_id', $company->id)
            ->where('accounting_source_type', 'bank_mapping')->count())->toBe(34)
        ->and(BankTransactionMapping::query()->where('company_id', $company->id)
            ->where('posting_status', BankPostingStatus::MatchedDoNotPost->value)->count())->toBe(6);

    $checks = app(AccountingReconciliationService::class)->checks($company->id, '2025-07-01', '2025-07-31');
    expect(collect($checks)->where('status', 'FAIL')->values()->all())->toBe([]);
});
