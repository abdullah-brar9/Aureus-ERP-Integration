<?php

namespace Webkul\Accounting\Services\Currency;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Services\Bank\BankStatementConversionService;
use Webkul\Security\Models\User;

class ExchangeRateApprovalService
{
    public function __construct(protected BankStatementConversionService $bankStatementConversions) {}

    public function approve(ExchangeRate $rate, User $approver): ExchangeRate
    {
        $approvedRate = DB::transaction(function () use ($rate, $approver): ExchangeRate {
            $rate = ExchangeRate::query()->lockForUpdate()->findOrFail($rate->id);

            if ($rate->source_currency_id === $rate->target_currency_id) {
                throw new RuntimeException('Identical currencies use the built-in identity rate and must not have a stored exchange rate.');
            }
            if (! BigDecimal::of((string) $rate->rate)->isGreaterThan(BigDecimal::zero())) {
                throw new RuntimeException('Exchange rates must be greater than zero.');
            }

            $duplicate = ExchangeRate::query()
                ->whereKeyNot($rate->id)
                ->where('company_id', $rate->company_id)
                ->where('source_currency_id', $rate->source_currency_id)
                ->where('target_currency_id', $rate->target_currency_id)
                ->whereDate('effective_date', $rate->effective_date)
                ->where('rate_type', $rate->rate_type->value)
                ->where('approval_status', ExchangeRateApprovalStatus::Approved->value)
                ->exists();

            if ($duplicate) {
                throw new RuntimeException('An approved rate already exists for this company, currency pair, date and rate type.');
            }

            $rate->update([
                'approval_status' => ExchangeRateApprovalStatus::Approved,
                'approved_by'     => $approver->id,
                'approved_at'     => now(),
            ]);

            return $rate->fresh(['sourceCurrency', 'targetCurrency', 'approver']);
        });

        $this->bankStatementConversions->refreshForCompany((int) $approvedRate->company_id);

        return $approvedRate;
    }

    public function reject(ExchangeRate $rate, User $approver, ?string $notes = null): ExchangeRate
    {
        $rate->update([
            'approval_status' => ExchangeRateApprovalStatus::Rejected,
            'approved_by'     => $approver->id,
            'approved_at'     => now(),
            'notes'           => $notes ?: $rate->notes,
        ]);

        return $rate->fresh();
    }
}
