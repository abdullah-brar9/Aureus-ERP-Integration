<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function bankInvoiceReconciliationFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $account = function (string $code, AccountType $type, bool $reconcile = false) use ($company, $currency, $user): Account {
        $account = Account::factory()->create([
            'code'        => $code.$company->id, 'name' => $code, 'account_type' => $type,
            'currency_id' => $currency->id, 'creator_id' => $user->id, 'reconcile' => $reconcile,
            'is_group'    => false, 'deprecated' => false,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };
    $bank = $account('BANK-', AccountType::ASSET_CASH, true);
    $receivable = $account('AR-', AccountType::ASSET_RECEIVABLE, true);
    $revenue = $account('REV-', AccountType::INCOME);
    $saleJournal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $revenue->id, 'code' => 'SALE'.$company->id, 'name' => 'Sales', 'type' => JournalType::SALE,
    ]);
    $bankJournal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $bank->id, 'code' => 'BANK'.$company->id, 'name' => 'Bank', 'type' => JournalType::BANK,
    ]);
    $partner = Partner::query()->create([
        'company_id'                     => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'reference'                      => 'CUSTOMER-'.$company->id, 'name' => 'Bank match customer', 'customer_rank' => 1,
        'property_account_receivable_id' => $receivable->id,
    ]);
    $invoice = Move::query()->create([
        'company_id'  => $company->id, 'journal_id' => $saleJournal->id, 'partner_id' => $partner->id,
        'currency_id' => $currency->id, 'move_type' => MoveType::OUT_INVOICE, 'state' => MoveState::DRAFT,
        'date'        => '2026-08-01', 'invoice_date' => '2026-08-01', 'invoice_date_due' => '2026-08-31',
        'reference'   => 'INV-BANK-5001', 'booking_id' => 'BKG-5001', 'consolidated_number' => 'CON-5001',
    ]);
    MoveLine::query()->create([
        'move_id'     => $invoice->id, 'account_id' => $revenue->id, 'partner_id' => $partner->id,
        'currency_id' => $currency->id, 'display_type' => DisplayType::PRODUCT,
        'name'        => 'Freight service', 'quantity' => 1, 'price_unit' => 1000,
    ]);
    $invoice = AccountFacade::confirmMove($invoice->fresh());

    return compact('currency', 'company', 'user', 'bank', 'receivable', 'revenue', 'bankJournal', 'partner', 'invoice');
}

function bankInvoiceMapping(array $fixture, string $amount): BankTransactionMapping
{
    $statement = BankStatement::query()->create([
        'company_id'              => $fixture['company']->id, 'journal_id' => $fixture['bankJournal']->id,
        'currency_id'             => $fixture['currency']->id, 'company_currency_id' => $fixture['currency']->id,
        'bank_gl_account_id'      => $fixture['bank']->id, 'name' => 'Invoice receipt', 'reference' => 'BANK-001',
        'date'                    => '2026-08-15', 'statement_start_date' => '2026-08-15', 'statement_end_date' => '2026-08-15',
        'opening_balance'         => 0, 'total_debits' => 0, 'total_credits' => $amount, 'closing_balance' => $amount,
        'balance_start'           => 0, 'balance_end' => $amount, 'balance_end_real' => $amount,
        'company_opening_balance' => 0, 'company_total_debits' => 0, 'company_total_credits' => $amount,
        'company_closing_balance' => $amount, 'conversion_status' => ConversionStatus::Complete,
        'bank_name'               => 'Acceptance Bank', 'bank_account_number' => 'BANK-001', 'account_title' => 'Operating',
        'original_filename'       => 'bank.csv', 'file_hash' => hash('sha256', $amount.$fixture['company']->id),
        'parser'                  => 'test', 'import_status' => BankImportStatus::Validated,
    ]);
    $line = BankStatementLine::query()->create([
        'journal_id'              => $fixture['bankJournal']->id, 'company_id' => $fixture['company']->id,
        'statement_id'            => $statement->id, 'currency_id' => $fixture['currency']->id,
        'original_currency_id'    => $fixture['currency']->id, 'company_currency_id' => $fixture['currency']->id,
        'transaction_date'        => '2026-08-15', 'value_date' => '2026-08-15',
        'description'             => 'Customer receipt for booking BKG-5001', 'reference' => 'BANK-UNRELATED',
        'debit'                   => 0, 'credit' => $amount, 'original_debit' => 0, 'original_credit' => $amount,
        'original_signed_amount'  => $amount, 'company_debit' => 0, 'company_credit' => $amount,
        'company_signed_amount'   => $amount, 'amount' => $amount, 'amount_currency' => $amount,
        'amount_residual'         => $amount, 'exchange_rate' => 1, 'rate_date' => '2026-08-15',
        'rate_source'             => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'transaction_fingerprint' => hash('sha256', 'BKG-5001-'.$amount.$fixture['company']->id),
        'import_status'           => BankImportStatus::Validated,
    ]);

    return BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id, 'statement_line_id' => $line->id,
        'bank_gl_account_id'  => $fixture['bank']->id, 'original_currency_id' => $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id, 'exchange_rate' => 1, 'rate_date' => '2026-08-15',
        'rate_source'         => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped, 'posting_status' => BankPostingStatus::NotPosted,
    ]);
}

it('matches booking identifiers and settles a posted customer invoice from the bank journal', function (): void {
    $fixture = bankInvoiceReconciliationFixture();
    $mapping = bankInvoiceMapping($fixture, '1000.0000');

    $result = app(BankMatchingPriorityService::class)->run($fixture['company']->id);
    $mapping->refresh();
    expect($result['obligations'])->toBe(1)
        ->and($mapping->matched_move_id)->toBe($fixture['invoice']->id)
        ->and($mapping->matched_reference)->toBe('BKG-5001')
        ->and($mapping->offset_account_id)->toBe($fixture['receivable']->id);

    app(BankMappingService::class)->approve($mapping, $fixture['user'], false);
    app(BankJournalService::class)->post($mapping->fresh(), $fixture['user']);

    expect((float) $fixture['invoice']->refresh()->amount_residual)->toBe(0.0)
        ->and($fixture['invoice']->payment_state)->toBe(PaymentState::PAID)
        ->and($mapping->refresh()->posting_status)->toBe(BankPostingStatus::Posted);
});

it('reconciles a smaller exact-reference receipt as a partial invoice payment', function (): void {
    $fixture = bankInvoiceReconciliationFixture();
    $mapping = bankInvoiceMapping($fixture, '400.0000');

    app(BankMatchingPriorityService::class)->run($fixture['company']->id);
    app(BankMappingService::class)->approve($mapping->refresh(), $fixture['user'], false);
    app(BankJournalService::class)->post($mapping->fresh(), $fixture['user']);

    expect((float) $fixture['invoice']->refresh()->amount_residual)->toBe(600.0)
        ->and($fixture['invoice']->payment_state)->toBe(PaymentState::PARTIAL);
});
