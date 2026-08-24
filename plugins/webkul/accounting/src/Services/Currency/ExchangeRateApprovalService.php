<?php

namespace Webkul\Accounting\Services\Currency;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Services\Bank\BankStatementConversionService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class ExchangeRateApprovalService
{
    public function __construct(
        protected BankStatementConversionService $bankStatementConversions,
        protected ApprovalEngine $approvals,
    ) {}

    public function requiresConfiguredApproval(ExchangeRate $rate): bool
    {
        return $this->approvals->matchingWorkflow(
            (int) $rate->company_id,
            'exchange_rate_change',
            null,
            $this->approvalContext($rate),
        ) !== null;
    }

    public function submit(ExchangeRate $rate, User $requester): ApprovalRequest
    {
        return $this->approvals->submit(
            $rate,
            $requester,
            'exchange_rate_change',
            null,
            $this->approvalContext($rate),
        );
    }

    public function approve(ExchangeRate $rate, User $approver): ExchangeRate
    {
        $approvedRate = DB::transaction(function () use ($rate, $approver): ExchangeRate {
            $rate = ExchangeRate::query()->lockForUpdate()->findOrFail($rate->id);

            if ($this->requiresConfiguredApproval($rate) && ! ApprovalRequest::query()
                ->where('company_id', $rate->company_id)
                ->where('request_type', 'exchange_rate_change')
                ->where('subject_type', $rate->getMorphClass())
                ->where('subject_id', $rate->id)
                ->where('status', 'approved')
                ->exists()) {
                throw new RuntimeException('This exchange rate requires a completed configured approval workflow before activation.');
            }

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

    /** @return array<string, mixed> */
    private function approvalContext(ExchangeRate $rate): array
    {
        return [
            'company_id'         => (int) $rate->company_id,
            'source_currency_id' => (int) $rate->source_currency_id,
            'target_currency_id' => (int) $rate->target_currency_id,
            'rate_type'          => $rate->rate_type?->value ?? (string) $rate->rate_type,
            'rate'               => (string) $rate->rate,
        ];
    }
}
