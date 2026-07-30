<?php

namespace Webkul\Accounting\Services\Currency;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class CompanyCurrencyService
{
    /**
     * @param  array<int, int|string>  $transactionCurrencyIds
     * @param  array<int, int|string>  $reportingCurrencyIds
     * @param  array<int, string>  $rateSourcePriority
     */
    public function update(
        Company $company,
        int $baseCurrencyId,
        array $transactionCurrencyIds,
        array $reportingCurrencyIds,
        ?int $fxGainAccountId,
        ?int $fxLossAccountId,
        array $rateSourcePriority,
        bool $allowPreviousRateFallback,
        string $pnlTranslationPolicy,
        string $balanceSheetTranslationPolicy,
    ): Company {
        $allowedSources = collect([
            ExchangeRateSource::BankStatement->value,
            ExchangeRateSource::Manual->value,
            ExchangeRateSource::Api->value,
            ExchangeRateSource::ImportedFile->value,
        ]);
        if (collect($rateSourcePriority)->diff($allowedSources)->isNotEmpty()) {
            throw new RuntimeException('The exchange-rate source priority contains an unsupported source.');
        }
        if (! in_array($pnlTranslationPolicy, ['transaction_date', 'monthly_average'], true)) {
            throw new RuntimeException('The P&L translation policy is not supported.');
        }
        if ($balanceSheetTranslationPolicy !== 'period_closing') {
            throw new RuntimeException('The Balance Sheet translation policy is not supported.');
        }

        $baseCurrency = Currency::query()->active()->findOrFail($baseCurrencyId);
        $transactionIds = collect($transactionCurrencyIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->push($baseCurrency->id)
            ->unique()
            ->values();
        $reportingIds = collect($reportingCurrencyIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();
        $allCurrencyIds = $transactionIds->merge($reportingIds)->unique()->values();

        $activeCurrencyCount = Currency::query()->active()->whereKey($allCurrencyIds)->count();
        if ($activeCurrencyCount !== $allCurrencyIds->count()) {
            throw new RuntimeException('Company currencies must be active ISO currencies.');
        }

        $this->assertFxAccount($company, $fxGainAccountId, 'gain');
        $this->assertFxAccount($company, $fxLossAccountId, 'loss');

        return DB::transaction(function () use (
            $company,
            $baseCurrency,
            $transactionIds,
            $reportingIds,
            $fxGainAccountId,
            $fxLossAccountId,
            $rateSourcePriority,
            $allowPreviousRateFallback,
            $pnlTranslationPolicy,
            $balanceSheetTranslationPolicy,
        ): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $company->update([
                'currency_id'                       => $baseCurrency->id,
                'fx_gain_account_id'                => $fxGainAccountId,
                'fx_loss_account_id'                => $fxLossAccountId,
                'rate_source_priority'              => array_values(array_unique($rateSourcePriority)),
                'allow_previous_rate_fallback'      => $allowPreviousRateFallback,
                'pnl_translation_policy'            => $pnlTranslationPolicy,
                'balance_sheet_translation_policy'  => $balanceSheetTranslationPolicy,
            ]);

            $pivot = $transactionIds->merge($reportingIds)->unique()->mapWithKeys(
                fn (int $currencyId): array => [$currencyId => [
                    'transaction_enabled' => $transactionIds->contains($currencyId),
                    'reporting_enabled'   => $reportingIds->contains($currencyId),
                ]],
            )->all();
            $company->enabledCurrencies()->sync($pivot);

            return $company->fresh(['currency', 'enabledCurrencies', 'fxGainAccount', 'fxLossAccount']);
        });
    }

    public function isTransactionCurrencyEnabled(Company $company, int $currencyId): bool
    {
        return $company->enabledCurrencies()
            ->where('currencies.id', $currencyId)
            ->wherePivot('transaction_enabled', true)
            ->exists();
    }

    private function assertFxAccount(Company $company, ?int $accountId, string $label): void
    {
        if ($accountId === null) {
            return;
        }

        $valid = Account::query()
            ->postable()
            ->whereKey($accountId)
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->exists();

        if (! $valid) {
            throw new RuntimeException("The FX {$label} account must be an active postable account owned by the company.");
        }
    }
}
