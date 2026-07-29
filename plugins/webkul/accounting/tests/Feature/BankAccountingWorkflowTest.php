<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Enums\ReportCompletenessStatus;
use Webkul\Accounting\Filament\Clusters\Accounting\Pages\ImportBankStatement;
use Webkul\Accounting\Filament\Clusters\Configuration\Pages\ImportedChartOfAccounts;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\AccountingChecks;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\DirectCashFlow;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\GeneralLedger;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Services\ManualAdjustmentService;
use Webkul\Accounting\Services\ReportCompletenessService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function bankAccountingWorkbookPath(): string
{
    $path = getenv('ACCOUNTING_WORKBOOK_FIXTURE') ?: 'C:/Users/HP/Downloads/Copy of preview.xlsx';

    if (! is_file($path)) {
        test()->markTestSkipped('Set ACCOUNTING_WORKBOOK_FIXTURE to Copy of preview.xlsx.');
    }

    return $path;
}

function bankWorkflowFixture(): array
{
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create([
        'currency_id' => $currency->id,
        'is_active'   => true,
    ]);
    $user = User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]);

    $account = function (string $code, string $name, AccountType $type) use ($currency, $company, $user): Account {
        $account = Account::factory()->create([
            'currency_id'  => $currency->id,
            'creator_id'   => $user->id,
            'code'         => $code,
            'name'         => $name,
            'account_type' => $type,
            'is_group'     => false,
            'deprecated'   => false,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };

    $hblBank = $account('WF-HBL-'.$company->id, 'HBL operating bank', AccountType::ASSET_CASH);
    $meezanBank = $account('WF-MEE-'.$company->id, 'Meezan payroll bank', AccountType::ASSET_CASH);
    $expense = $account('WF-EXP-'.$company->id, 'Workflow expense', AccountType::EXPENSE);
    $liability = $account('WF-LIA-'.$company->id, 'Workflow liability', AccountType::LIABILITY_CURRENT);

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

    return [
        'currency'        => $currency,
        'company'         => $company,
        'user'            => $user,
        'hbl_bank'        => $hblBank,
        'meezan_bank'     => $meezanBank,
        'expense'         => $expense,
        'liability'       => $liability,
        'hbl_journal'     => $journal('BH'.$company->id, 'HBL workflow', JournalType::BANK, $hblBank),
        'meezan_journal'  => $journal('BM'.$company->id, 'Meezan workflow', JournalType::BANK, $meezanBank),
        'general_journal' => $journal('BG'.$company->id, 'General workflow', JournalType::GENERAL, $expense),
    ];
}

function importWorkbookBankStatements(array $fixture): array
{
    $service = app(BankStatementImportService::class);
    $path = bankAccountingWorkbookPath();

    return [
        'hbl' => $service->import(
            $path,
            $fixture['company'],
            $fixture['hbl_journal'],
            $fixture['hbl_bank'],
            $fixture['currency'],
            'hbl',
        ),
        'meezan' => $service->import(
            $path,
            $fixture['company'],
            $fixture['meezan_journal'],
            $fixture['meezan_bank'],
            $fixture['currency'],
            'meezan',
        ),
    ];
}

it('imports both sheets from one workbook and rejects only the duplicate source', function (): void {
    $fixture = bankWorkflowFixture();
    $statements = importWorkbookBankStatements($fixture);

    expect($statements['hbl']->lines)->toHaveCount(34)
        ->and($statements['meezan']->lines)->toHaveCount(25)
        ->and(BankStatement::query()->where('company_id', $fixture['company']->id)->count())->toBe(2)
        ->and(BankTransactionMapping::query()->where('company_id', $fixture['company']->id)->count())->toBe(59);

    expect(fn () => app(BankStatementImportService::class)->import(
        bankAccountingWorkbookPath(),
        $fixture['company'],
        $fixture['hbl_journal'],
        $fixture['hbl_bank'],
        $fixture['currency'],
        'hbl',
    ))->toThrow(RuntimeException::class, 'already been imported');
});

it('detects both workbook transfer pairs without duplicating income or expense', function (): void {
    $fixture = bankWorkflowFixture();
    importWorkbookBankStatements($fixture);

    $matches = app(BankTransferMatchingService::class)->detect($fixture['company']->id);

    expect($matches)->toHaveCount(2)
        ->and(collect($matches)->pluck('match_reference')->sort()->values()->all())->toBe(['TRF-0707', 'TRF-0721']);

    foreach ($matches as $match) {
        expect(BankTransactionMapping::query()->where('transfer_match_id', $match->id)->count())->toBe(2)
            ->and((float) $match->outgoingLine->debit)->toBe((float) $match->incomingLine->credit);
    }

    expect(app(BankTransferMatchingService::class)->detect($fixture['company']->id))->toBe([]);
});

it('keeps a failed reconciliation out of the posting pipeline', function (): void {
    $fixture = bankWorkflowFixture();
    $wrongCurrency = Currency::query()->where('name', '!=', 'PKR')->firstOrFail();
    $fixture['hbl_journal']->update(['currency_id' => $wrongCurrency->id]);
    $fixture['hbl_bank']->update(['currency_id' => $wrongCurrency->id]);
    $statement = app(BankStatementImportService::class)->import(
        bankAccountingWorkbookPath(),
        $fixture['company'],
        $fixture['hbl_journal'],
        $fixture['hbl_bank'],
        $wrongCurrency,
        'hbl',
    );

    expect($statement->import_status)->toBe(BankImportStatus::ReconciliationFailed->value)
        ->and(collect($statement->validation_errors)->pluck('code'))->toContain('currency_mismatch');

    $mapping = $statement->lines()->firstOrFail()->mapping;
    $mapping->update([
        'offset_account_id'  => $fixture['expense']->id,
        'cash_flow_category' => CashFlowCategory::OperatingPayments,
    ]);
    $mapping = app(BankMappingService::class)->approve($mapping->fresh(), $fixture['user']);

    expect(fn () => app(BankJournalService::class)->createDraft($mapping))
        ->toThrow(RuntimeException::class, 'Only reconciled, validated statements');
});

it('approves a mapping, creates one balanced draft, and posts idempotently', function (): void {
    $fixture = bankWorkflowFixture();
    importWorkbookBankStatements($fixture);
    app(BankTransferMatchingService::class)->detect($fixture['company']->id);

    $mapping = BankTransactionMapping::query()
        ->where('company_id', $fixture['company']->id)
        ->whereNull('transfer_match_id')
        ->with('statementLine')
        ->firstOrFail();
    $mapping->update([
        'offset_account_id'  => $fixture['expense']->id,
        'transaction_type'   => 'Operating item',
        'cash_flow_category' => (float) $mapping->statementLine->credit > 0
            ? CashFlowCategory::OperatingReceipts
            : CashFlowCategory::OperatingPayments,
    ]);

    $mapping = app(BankMappingService::class)->approve($mapping->fresh(), $fixture['user']);
    expect($mapping->review_status)->toBe(BankReviewStatus::Approved)
        ->and($mapping->mapping_rule_id)->not->toBeNull();

    $move = app(BankJournalService::class)->createDraft($mapping);
    $amount = max((float) $mapping->statementLine->debit, (float) $mapping->statementLine->credit);
    expect($move->state)->toBe(MoveState::DRAFT)
        ->and((float) $move->lines->sum('debit'))->toBe($amount)
        ->and((float) $move->lines->sum('credit'))->toBe($amount);

    $draftTrialBalance = app(TrialBalanceService::class)->compute($fixture['company']->id, '2025-07-01', '2025-07-31');
    expect($draftTrialBalance['totals']['closing_debit'])->toBe(0.0)
        ->and($draftTrialBalance['totals']['closing_credit'])->toBe(0.0);

    $posted = app(BankJournalService::class)->post($mapping->fresh(), $fixture['user']);
    $postedAgain = app(BankJournalService::class)->post($mapping->fresh(), $fixture['user']);
    expect($posted->state)->toBe(MoveState::POSTED)
        ->and($postedAgain->id)->toBe($posted->id)
        ->and(DB::table('accounts_account_moves')->where('accounting_source_type', 'bank_mapping')->where('accounting_source_id', $mapping->id)->count())->toBe(1);

    $postedTrialBalance = app(TrialBalanceService::class)->compute($fixture['company']->id, '2025-07-01', '2025-07-31');
    expect($postedTrialBalance['totals']['closing_debit'])->toBe($amount)
        ->and($postedTrialBalance['totals']['closing_credit'])->toBe($amount)
        ->and($postedTrialBalance['totals']['difference'])->toBe(0.0);
});

it('moves report completeness from review to opening and manual adjustment completion', function (): void {
    $fixture = bankWorkflowFixture();
    importWorkbookBankStatements($fixture);
    $service = app(ReportCompletenessService::class);

    expect($service->assess($fixture['company']->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::AwaitingReview);

    BankTransactionMapping::query()->where('company_id', $fixture['company']->id)->update([
        'review_status'  => BankReviewStatus::DoNotPost,
        'posting_status' => BankPostingStatus::DoNotPost,
    ]);
    expect($service->assess($fixture['company']->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::MissingOpeningBalances);

    $adjustments = app(ManualAdjustmentService::class);
    $opening = ManualAdjustment::query()->create([
        'company_id'            => $fixture['company']->id,
        'journal_id'            => $fixture['general_journal']->id,
        'debit_account_id'      => $fixture['expense']->id,
        'credit_account_id'     => $fixture['liability']->id,
        'date'                  => '2025-06-30',
        'amount'                => 1000,
        'description'           => 'Opening balance fixture',
        'source_classification' => 'opening_balances',
        'approval_status'       => ManualAdjustmentStatus::Draft,
    ]);
    $adjustments->approve($opening, $fixture['user']);
    $adjustments->post($opening->fresh(), $fixture['user']);

    expect($service->assess($fixture['company']->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::MissingNonBankAdjustments);

    $periodAdjustment = ManualAdjustment::query()->create([
        'company_id'            => $fixture['company']->id,
        'journal_id'            => $fixture['general_journal']->id,
        'debit_account_id'      => $fixture['expense']->id,
        'credit_account_id'     => $fixture['liability']->id,
        'date'                  => '2025-07-31',
        'amount'                => 250,
        'description'           => 'Period-close accrual fixture',
        'source_classification' => 'accrual',
        'approval_status'       => ManualAdjustmentStatus::Draft,
    ]);
    $adjustments->approve($periodAdjustment, $fixture['user']);
    $adjustments->post($periodAdjustment->fresh(), $fixture['user']);

    expect($service->assess($fixture['company']->id, '2025-07-01', '2025-07-31')['status'])
        ->toBe(ReportCompletenessStatus::Complete);
});

it('keeps every company postable account available and honors the General Ledger zero toggles', function (): void {
    $fixture = bankWorkflowFixture();
    $otherCompany = Company::factory()->create([
        'currency_id' => $fixture['currency']->id,
        'is_active'   => true,
    ]);
    $otherAccount = Account::factory()->create([
        'currency_id' => $fixture['currency']->id,
        'code'        => 'WF-OTHER-'.$otherCompany->id,
        'is_group'    => false,
        'deprecated'  => false,
    ]);
    $otherAccount->companies()->attach($otherCompany->id);

    Permission::findOrCreate('page_accounting_general_ledger', 'web');
    $fixture['user']->givePermissionTo('page_accounting_general_ledger');
    test()->actingAs($fixture['user']);

    $component = Livewire::test(GeneralLedger::class)->assertOk();
    $accountIds = $component->instance()->generalLedgerData()['accounts']->pluck('id');

    expect($accountIds)->toContain($fixture['hbl_bank']->id)
        ->and($accountIds)->toContain($fixture['meezan_bank']->id)
        ->and($accountIds)->toContain($fixture['expense']->id)
        ->and($accountIds)->not->toContain($otherAccount->id);

    $component->set('data.show_zero_activity', false);
    expect($component->instance()->generalLedgerData()['accounts'])->toHaveCount(0);

    $component->set('data.show_zero_activity', true)
        ->set('data.show_zero_balance', false);
    expect($component->instance()->generalLedgerData()['accounts'])->toHaveCount(0);
});

it('renders the imported CoA, bank import, cash flow, and accounting checks pages', function (): void {
    $fixture = bankWorkflowFixture();
    $permissions = [
        'page_accounting_imported_chart_of_accounts',
        'page_accounting_import_bank_statement',
        'page_accounting_direct_cash_flow',
        'page_accounting_accounting_checks',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    test()->actingAs($fixture['user']);

    foreach ([
        ImportedChartOfAccounts::getUrl(),
        ImportBankStatement::getUrl(),
        DirectCashFlow::getUrl(),
        AccountingChecks::getUrl(),
    ] as $url) {
        test()->get($url)->assertOk();
    }
});
