<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;
use Webkul\Accounting\Services\Bank\BankStatementValidationService;
use Webkul\Accounting\Services\Bank\HblBankStatementParser;
use Webkul\Accounting\Services\Bank\MeezanBankStatementParser;
use Webkul\Accounting\Services\Coa\CoaAccountTypeMapper;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function bankWorkflowWorkbookPath(): string
{
    $path = getenv('ACCOUNTING_WORKBOOK_FIXTURE');

    if (! is_string($path) || $path === '' || ! is_file($path)) {
        test()->markTestSkipped('Set ACCOUNTING_WORKBOOK_FIXTURE to Copy of preview.xlsx.');
    }

    return $path;
}

it('preserves the exact chart headers, classifications, and source row order', function (): void {
    $source = (new CoaSheetReader)->readWithSource(bankWorkflowWorkbookPath());
    $map = (new CoaHeaderDetector)->detect($source['rows']);
    $rows = (new CoaSheetParser)->parse($source['rows'], $map);

    expect($source['sheet_name'])->toBe('Chart of Accounts')
        ->and($map->headerRowIndex + 1)->toBe(3)
        ->and($rows)->toHaveCount(59)
        ->and($rows[0]->sourceHeaders)->toBe([
            'Nature',
            'Classification 1',
            'Classification 2',
            'Classification 3',
            'Classification 4',
            'Classification 5',
            'Classification 7',
            'Code',
            'Title',
        ])
        ->and($rows[0]->sheetRow)->toBe(4)
        ->and($rows[0]->code)->toBe('1011')
        ->and($rows[0]->title)->toBe('Furniture and fixture')
        ->and($rows[0]->classificationValues)->toBe([
            'Classification 1' => 'Assets',
            'Classification 2' => 'Non Current Assets',
            'Classification 3' => 'Property and equipment',
            'Classification 4' => 'Cost',
            'Classification 5' => 'Cost',
            'Classification 7' => 'Furniture and fixture',
        ])
        ->and($rows[58]->sheetRow)->toBe(62)
        ->and($rows[58]->code)->toBe('1069')
        ->and($rows[58]->title)->toBe('Bank Charges');

    $byCode = collect($rows)->keyBy('code');
    $typeMapper = new CoaAccountTypeMapper;
    expect($typeMapper->suggest($byCode['1029']))->toBe(AccountType::ASSET_CASH)
        ->and($typeMapper->suggest($byCode['1030']))->toBe(AccountType::ASSET_CASH)
        ->and($typeMapper->suggest($byCode['1057']))->toBe(AccountType::EXPENSE)
        ->and($typeMapper->suggest($byCode['1069']))->toBe(AccountType::EXPENSE);
});

it('parses and reconciles both workbook bank statements', function (): void {
    $path = bankWorkflowWorkbookPath();
    $validator = new BankStatementValidationService;
    $hbl = (new HblBankStatementParser)->parse($path);
    $meezan = (new MeezanBankStatementParser)->parse($path);

    expect($hbl->sourceSheet)->toBe('HBL Operating Account')
        ->and($hbl->transactions)->toHaveCount(34)
        ->and($hbl->openingBalance)->toBe('3250000.0000')
        ->and($hbl->totalDebits)->toBe('3437724.0000')
        ->and($hbl->totalCredits)->toBe('4185650.0000')
        ->and($hbl->closingBalance)->toBe('3997926.0000')
        ->and($validator->validate($hbl))->toBe([])
        ->and($meezan->sourceSheet)->toBe('Meezan Payroll Account')
        ->and($meezan->transactions)->toHaveCount(25)
        ->and($meezan->openingBalance)->toBe('480000.0000')
        ->and($meezan->totalDebits)->toBe('1875588.0000')
        ->and($meezan->totalCredits)->toBe('1454500.0000')
        ->and($meezan->closingBalance)->toBe('58912.0000')
        ->and($validator->validate($meezan))->toBe([])
        ->and(count($hbl->transactions) + count($meezan->transactions))->toBe(59)
        ->and((float) $hbl->openingBalance + (float) $meezan->openingBalance)->toBe(3730000.0)
        ->and((float) $hbl->closingBalance + (float) $meezan->closingBalance)->toBe(4056838.0)
        ->and(((float) $hbl->closingBalance + (float) $meezan->closingBalance) - ((float) $hbl->openingBalance + (float) $meezan->openingBalance))->toBe(326838.0);
});

it('blocks duplicate transaction fingerprints and failed statement reconciliation', function (): void {
    $original = (new HblBankStatementParser)->parse(bankWorkflowWorkbookPath());
    $invalid = new NormalizedBankStatement(
        bank: $original->bank,
        bankAccountNumber: $original->bankAccountNumber,
        accountTitle: $original->accountTitle,
        currency: $original->currency,
        statementStartDate: $original->statementStartDate,
        statementEndDate: $original->statementEndDate,
        openingBalance: $original->openingBalance,
        totalDebits: $original->totalDebits,
        totalCredits: $original->totalCredits,
        closingBalance: (string) ((float) $original->closingBalance + 1),
        parser: $original->parser,
        sourceSheet: $original->sourceSheet,
        rawHeader: $original->rawHeader,
        transactions: [...$original->transactions, $original->transactions[0]],
    );

    $codes = collect((new BankStatementValidationService)->validate($invalid))->pluck('code');

    expect($codes)->toContain('header_reconciliation')
        ->and($codes)->toContain('duplicate_row');
});

it('persists every nine-column source row and links it to the company-scoped canonical account', function (): void {
    $path = bankWorkflowWorkbookPath();
    $source = (new CoaSheetReader)->readWithSource($path);
    $map = (new CoaHeaderDetector)->detect($source['rows']);
    $rows = (new CoaSheetParser)->parse($source['rows'], $map);
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $batch = app(CoaImportService::class)->import(
        rows: $rows,
        company: $company,
        mode: 'structure_only',
        currencyId: $currency->id,
        filename: basename($path),
        sourceSheet: $source['sheet_name'],
        fileHash: hash_file('sha256', $path),
        headerRowNumber: $map->headerRowIndex + 1,
        sourceHeaders: $rows[0]->sourceHeaders,
        metadataRows: array_slice($source['rows'], 0, $map->headerRowIndex),
    );

    $first = $batch->sourceRows()->with('canonicalAccount')->orderBy('row_order')->firstOrFail();
    $last = $batch->sourceRows()->reorder('row_order', 'desc')->firstOrFail();

    expect($batch->original_headers)->toBe($rows[0]->sourceHeaders)
        ->and($batch->sourceRows()->count())->toBe(59)
        ->and($first->row_order)->toBe(1)
        ->and($first->source_row_number)->toBe(4)
        ->and($first->classification_1)->toBe('Assets')
        ->and($first->classification_7)->toBe('Furniture and fixture')
        ->and($first->raw_row_by_header['Title'])->toBe('Furniture and fixture')
        ->and($first->canonicalAccount?->code)->toBe('1011')
        ->and($last->row_order)->toBe(59)
        ->and($last->code)->toBe('1069')
        ->and($first->canonicalAccount->companies()->where('companies.id', $company->id)->exists())->toBeTrue()
        ->and($first->canonicalAccount->companies()->where('companies.id', $otherCompany->id)->exists())->toBeFalse();
});
