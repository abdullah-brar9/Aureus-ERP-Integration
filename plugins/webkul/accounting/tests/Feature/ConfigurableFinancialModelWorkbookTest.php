<?php

use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function configurableFinancialModelPath(): string
{
    $path = getenv('ACCOUNTING_TEN_BANK_WORKBOOK_FIXTURE');

    if (! is_string($path) || $path === '' || ! is_file($path)) {
        test()->markTestSkipped('Set ACCOUNTING_TEN_BANK_WORKBOOK_FIXTURE to ten_bank_fs_tagged_financial_model.xlsx.');
    }

    return $path;
}

function configurableFinancialModelFixture(): array
{
    $path = configurableFinancialModelPath();
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $currency->update(['active' => true, 'is_iso_fiat' => true]);
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $source = (new CoaSheetReader)->readWithSource($path);
    $columnMap = (new CoaHeaderDetector)->detect($source['rows']);
    $coaRows = (new CoaSheetParser)->parse($source['rows'], $columnMap);
    app(CoaImportService::class)->import(
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

    $defaultAccount = Account::query()
        ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
        ->where('code', '1018')
        ->firstOrFail();
    $journal = Journal::factory()->create([
        'company_id'         => $company->id,
        'currency_id'        => $currency->id,
        'creator_id'         => $user->id,
        'default_account_id' => $defaultAccount->id,
        'code'               => 'CFG'.$company->id,
        'name'               => 'Configured workbook import',
        'type'               => JournalType::GENERAL,
    ]);

    return compact('path', 'currency', 'company', 'user', 'journal');
}

it('imports the exact opening balance sheet as one posted classified journal', function (): void {
    $fixture = configurableFinancialModelFixture();
    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Financial model opening balances',
        'entity_type'    => 'opening_balance',
        'file_type'      => 'xlsx',
        'sheet_name'     => 'Opening Balances',
        'header_row'     => 2,
        'data_start_row' => 3,
        'blank_row_rule' => 'skip',
        'failure_policy' => 'reject_file',
        'version'        => 1,
        'is_active'      => true,
    ]);
    $profile->mappings()->createMany([
        ['position' => 1, 'source_header' => 'GL Code', 'target_field' => 'gl_code', 'transformations' => [['type' => 'trim']], 'is_required' => true],
        ['position' => 2, 'source_header' => 'GL Account', 'target_field' => 'gl_account', 'transformations' => [['type' => 'trim']]],
        ['position' => 3, 'source_header' => 'Source Classification', 'target_field' => 'source_classification', 'transformations' => [['type' => 'trim']]],
        ['position' => 4, 'source_header' => 'Opening Debit', 'target_field' => 'debit', 'transformations' => [['type' => 'null_if', 'values' => ['-']], ['type' => 'default', 'value' => '0'], ['type' => 'decimal', 'scale' => 4]], 'is_required' => true],
        ['position' => 5, 'source_header' => 'Opening Credit', 'target_field' => 'credit', 'transformations' => [['type' => 'null_if', 'values' => ['-']], ['type' => 'default', 'value' => '0'], ['type' => 'decimal', 'scale' => 4]], 'is_required' => true],
        ['position' => 6, 'source_header' => 'Note', 'target_field' => 'note', 'transformations' => [['type' => 'trim']]],
        ['position' => 7, 'source_header' => '__opening_date', 'target_field' => 'date', 'transformations' => [['type' => 'default', 'value' => '2025-06-30']], 'is_required' => true],
        ['position' => 8, 'source_header' => '__currency', 'target_field' => 'currency', 'transformations' => [['type' => 'default', 'value' => 'PKR']], 'is_required' => true],
        ['position' => 9, 'source_header' => '__journal', 'target_field' => 'journal_code', 'transformations' => [['type' => 'default', 'value' => $fixture['journal']->code]], 'is_required' => true],
    ]);

    $run = app(ImportPreviewService::class)->preview($profile, $fixture['path'], basename($fixture['path']), $fixture['user']->id);
    expect($run->total_rows)->toBe(62)
        ->and($run->passed_rows)->toBe(62)
        ->and($run->failed_rows)->toBe(0);

    $run = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
    $move = Move::query()->where('accounting_source_type', get_class($run))->where('accounting_source_id', $run->id)->firstOrFail();

    expect($run->imported_rows)->toBe(62)
        ->and($move->state->value)->toBe('posted')
        ->and($move->coa_migration_kind)->toBe('opening')
        ->and((float) $move->lines()->sum('debit'))->toBe(7425000.0)
        ->and((float) $move->lines()->sum('credit'))->toBe(7425000.0)
        ->and($run->sourceRows()->whereNull('canonical_id')->count())->toBe(0);
});

it('imports the exact non-bank sheet as a balanced draft requiring review', function (): void {
    $fixture = configurableFinancialModelFixture();
    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Financial model non-bank entries',
        'entity_type'    => 'journal_entry',
        'file_type'      => 'xlsx',
        'sheet_name'     => 'Non-Bank Entries',
        'header_row'     => 2,
        'data_start_row' => 3,
        'blank_row_rule' => 'skip',
        'failure_policy' => 'reject_file',
        'version'        => 1,
        'is_active'      => true,
    ]);
    $profile->mappings()->createMany([
        ['position' => 1, 'source_header' => 'JE ID', 'target_field' => 'journal_entry_id', 'transformations' => [['type' => 'trim']], 'is_required' => true],
        ['position' => 2, 'source_header' => 'Date', 'target_field' => 'date', 'transformations' => [['type' => 'date']], 'is_required' => true],
        ['position' => 3, 'source_header' => 'Description', 'target_field' => 'description', 'transformations' => [['type' => 'trim']]],
        ['position' => 4, 'source_header' => 'GL Code', 'target_field' => 'gl_code', 'transformations' => [['type' => 'trim']], 'is_required' => true],
        ['position' => 5, 'source_header' => 'GL Account', 'target_field' => 'gl_account', 'transformations' => [['type' => 'trim']]],
        ['position' => 6, 'source_header' => 'Debit', 'target_field' => 'debit', 'transformations' => [['type' => 'null_if', 'values' => ['-']], ['type' => 'default', 'value' => '0'], ['type' => 'decimal', 'scale' => 4]], 'is_required' => true],
        ['position' => 7, 'source_header' => 'Credit', 'target_field' => 'credit', 'transformations' => [['type' => 'null_if', 'values' => ['-']], ['type' => 'default', 'value' => '0'], ['type' => 'decimal', 'scale' => 4]], 'is_required' => true],
        ['position' => 8, 'source_header' => 'Entity', 'target_field' => 'entity', 'transformations' => [['type' => 'trim']]],
        ['position' => 9, 'source_header' => 'Tax Treatment', 'target_field' => 'tax_treatment', 'transformations' => [['type' => 'trim']]],
        ['position' => 10, 'source_header' => 'Posting Status', 'target_field' => 'status', 'transformations' => [['type' => 'trim']]],
        ['position' => 11, 'source_header' => 'Cash Flow Category', 'target_field' => 'cash_flow_category', 'transformations' => [['type' => 'trim']]],
        ['position' => 12, 'source_header' => 'FS Taggings name', 'target_field' => 'fs_tag_name', 'transformations' => [['type' => 'trim']]],
        ['position' => 13, 'source_header' => 'FS Tag', 'target_field' => 'fs_tag', 'transformations' => [['type' => 'trim']]],
        ['position' => 14, 'source_header' => '__currency', 'target_field' => 'currency', 'transformations' => [['type' => 'default', 'value' => 'PKR']], 'is_required' => true],
        ['position' => 15, 'source_header' => '__journal', 'target_field' => 'journal_code', 'transformations' => [['type' => 'default', 'value' => $fixture['journal']->code]], 'is_required' => true],
    ]);

    $run = app(ImportPreviewService::class)->preview($profile, $fixture['path'], basename($fixture['path']), $fixture['user']->id);
    expect($run->total_rows)->toBe(2)
        ->and($run->passed_rows)->toBe(2)
        ->and($run->failed_rows)->toBe(0)
        ->and($run->sourceRows->first()->transformed_values['date'])->toBe('2025-07-31');

    $run = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
    $move = Move::query()->where('reference', 'NB-001')->where('company_id', $fixture['company']->id)->firstOrFail();

    expect($run->imported_rows)->toBe(2)
        ->and($move->state->value)->toBe('draft')
        ->and($move->review_status)->toBe('needs_review')
        ->and((float) $move->lines()->sum('debit'))->toBe(50000.0)
        ->and((float) $move->lines()->sum('credit'))->toBe(50000.0)
        ->and($move->lines()->count())->toBe(2);
});
