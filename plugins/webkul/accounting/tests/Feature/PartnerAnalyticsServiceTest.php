<?php

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Services\PartnerAnalyticsService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

it('calculates posted customer analytics by company with refunds, aging, concentration, and trends', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $alpha = Partner::query()->create([
        'company_id' => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'name'       => 'Alpha Customer', 'customer_rank' => 1,
    ]);
    $beta = Partner::query()->create([
        'company_id' => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'name'       => 'Beta Customer', 'customer_rank' => 1,
    ]);
    $outside = Partner::query()->create([
        'company_id' => $otherCompany->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'name'       => 'Outside Customer', 'customer_rank' => 1,
    ]);
    $now = now();
    DB::table('accounts_account_moves')->insert([
        ['company_id' => $company->id, 'partner_id' => $alpha->id, 'state' => MoveState::POSTED->value, 'move_type' => MoveType::OUT_INVOICE->value, 'date' => '2026-01-15', 'invoice_date' => '2026-01-15', 'invoice_date_due' => '2026-02-15', 'amount_total' => 1000, 'amount_residual' => 400, 'created_at' => $now, 'updated_at' => $now],
        ['company_id' => $company->id, 'partner_id' => $alpha->id, 'state' => MoveState::POSTED->value, 'move_type' => MoveType::OUT_REFUND->value, 'date' => '2026-02-01', 'invoice_date' => '2026-02-01', 'invoice_date_due' => '2026-02-01', 'amount_total' => 100, 'amount_residual' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['company_id' => $company->id, 'partner_id' => $beta->id, 'state' => MoveState::POSTED->value, 'move_type' => MoveType::OUT_INVOICE->value, 'date' => '2026-02-20', 'invoice_date' => '2026-02-20', 'invoice_date_due' => '2026-03-20', 'amount_total' => 500, 'amount_residual' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['company_id' => $otherCompany->id, 'partner_id' => $outside->id, 'state' => MoveState::POSTED->value, 'move_type' => MoveType::OUT_INVOICE->value, 'date' => '2026-02-20', 'invoice_date' => '2026-02-20', 'invoice_date_due' => '2026-03-20', 'amount_total' => 9999, 'amount_residual' => 9999, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $analytics = app(PartnerAnalyticsService::class)->summary($company->id, 'customer', '2026-01-01', '2026-12-31');

    expect($analytics['party_count'])->toBe(2)
        ->and($analytics['document_count'])->toBe(3)
        ->and($analytics['document_value'])->toBe(1400.0)
        ->and($analytics['outstanding'])->toBe(400.0)
        ->and($analytics['overdue'])->toBe(400.0)
        ->and($analytics['overdue_rate'])->toBe(100.0)
        ->and($analytics['top_concentration'])->toBe(64.29)
        ->and($analytics['top_parties'][0]['name'])->toBe('Alpha Customer')
        ->and($analytics['top_parties'][0]['document_value'])->toBe(900.0)
        ->and($analytics['trends'])->toHaveCount(2)
        ->and($analytics['payment_count'])->toBe(0);
});
