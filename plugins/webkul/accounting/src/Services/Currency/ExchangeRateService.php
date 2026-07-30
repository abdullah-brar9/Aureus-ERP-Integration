<?php

namespace Webkul\Accounting\Services\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Webkul\Accounting\Data\ResolvedExchangeRate;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class ExchangeRateService
{
    /**
     * @param  array<int, ExchangeRateType|string>  $rateTypes
     */
    public function resolve(
        Company $company,
        Currency $sourceCurrency,
        Currency $targetCurrency,
        string $effectiveDate,
        array $rateTypes = [ExchangeRateType::Transaction, ExchangeRateType::Daily],
        ?bool $allowPrevious = null,
    ): ResolvedExchangeRate {
        if ($sourceCurrency->is($targetCurrency)) {
            return new ResolvedExchangeRate(
                rate: '1.000000000000000',
                recordId: null,
                effectiveDate: $effectiveDate,
                source: ExchangeRateSource::Identity->value,
                type: ExchangeRateType::Transaction->value,
            );
        }

        $typeValues = collect($rateTypes)
            ->map(fn (ExchangeRateType|string $type): string => $type instanceof ExchangeRateType ? $type->value : $type)
            ->unique()
            ->values()
            ->all();
        $allowPrevious ??= (bool) $company->allow_previous_rate_fallback;
        $version = Cache::get("accounting.exchange-rate-version.{$company->id}", '1');
        $cacheKey = implode(':', [
            'accounting.exchange-rate', $version, $company->id, $sourceCurrency->id,
            $targetCurrency->id, $effectiveDate, implode(',', $typeValues), (int) $allowPrevious,
        ]);

        return Cache::remember($cacheKey, now()->addHour(), function () use (
            $company,
            $sourceCurrency,
            $targetCurrency,
            $effectiveDate,
            $typeValues,
            $allowPrevious,
        ): ResolvedExchangeRate {
            $direct = $this->findCandidate(
                $company,
                $sourceCurrency,
                $targetCurrency,
                $effectiveDate,
                $typeValues,
                false,
            );

            if ($direct) {
                return $this->toResolved($direct, $effectiveDate);
            }

            $inverse = $this->findCandidate(
                $company,
                $targetCurrency,
                $sourceCurrency,
                $effectiveDate,
                $typeValues,
                false,
            );

            if ($inverse) {
                return $this->toResolved($inverse, $effectiveDate, true);
            }

            if ($allowPrevious) {
                $previousDirect = $this->findCandidate(
                    $company,
                    $sourceCurrency,
                    $targetCurrency,
                    $effectiveDate,
                    $typeValues,
                    true,
                );

                if ($previousDirect) {
                    return $this->toResolved($previousDirect, $effectiveDate);
                }

                $previousInverse = $this->findCandidate(
                    $company,
                    $targetCurrency,
                    $sourceCurrency,
                    $effectiveDate,
                    $typeValues,
                    true,
                );

                if ($previousInverse) {
                    return $this->toResolved($previousInverse, $effectiveDate, true);
                }
            }

            throw MissingExchangeRateException::forPair(
                $sourceCurrency->code ?? $sourceCurrency->name,
                $targetCurrency->code ?? $targetCurrency->name,
                $effectiveDate,
                implode('/', $typeValues),
            );
        });
    }

    public function resolveForBankTransaction(
        Company $company,
        Currency $sourceCurrency,
        Currency $targetCurrency,
        string $effectiveDate,
    ): ResolvedExchangeRate {
        return $this->resolve(
            $company,
            $sourceCurrency,
            $targetCurrency,
            $effectiveDate,
            [ExchangeRateType::BankProvided, ExchangeRateType::Transaction, ExchangeRateType::Daily],
        );
    }

    public function convert(string|int $amount, ResolvedExchangeRate $rate, int $decimalPlaces = 4): string
    {
        return BigDecimal::of((string) $amount)
            ->multipliedBy($rate->rate)
            ->toScale($decimalPlaces, RoundingMode::HalfUp)
            ->__toString();
    }

    /**
     * @param  array<int, string>  $rateTypes
     */
    private function findCandidate(
        Company $company,
        Currency $sourceCurrency,
        Currency $targetCurrency,
        string $effectiveDate,
        array $rateTypes,
        bool $allowPrevious,
    ): ?ExchangeRate {
        $query = ExchangeRate::query()
            ->where('company_id', $company->id)
            ->where('source_currency_id', $sourceCurrency->id)
            ->where('target_currency_id', $targetCurrency->id)
            ->where('approval_status', ExchangeRateApprovalStatus::Approved->value)
            ->whereIn('rate_type', $rateTypes);

        if ($allowPrevious) {
            $query->whereDate('effective_date', '<=', $effectiveDate)->orderByDesc('effective_date');
        } else {
            $query->whereDate('effective_date', $effectiveDate);
        }

        $candidates = $query->limit(50)->get();
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($allowPrevious) {
            $latestDate = $candidates->max(fn (ExchangeRate $rate): string => $rate->effective_date->toDateString());
            $candidates = $candidates->filter(fn (ExchangeRate $rate): bool => $rate->effective_date->toDateString() === $latestDate);
        }

        return $this->sortByPriority($candidates, $company)->first();
    }

    private function sortByPriority(Collection $rates, Company $company): Collection
    {
        $configuredSources = collect($company->rate_source_priority ?: [
            ExchangeRateSource::BankStatement->value,
            ExchangeRateSource::Manual->value,
            ExchangeRateSource::Api->value,
            ExchangeRateSource::ImportedFile->value,
        ])->values();

        return $rates->sortBy(function (ExchangeRate $rate) use ($configuredSources): string {
            $source = $rate->source instanceof ExchangeRateSource ? $rate->source->value : (string) $rate->source;
            $type = $rate->rate_type instanceof ExchangeRateType ? $rate->rate_type->value : (string) $rate->rate_type;

            $priority = match (true) {
                $source === ExchangeRateSource::BankStatement->value || $type === ExchangeRateType::BankProvided->value => 0,
                $source === ExchangeRateSource::Manual->value && $type === ExchangeRateType::Transaction->value         => 1,
                $source === ExchangeRateSource::Api->value && $type === ExchangeRateType::Daily->value                  => 2,
                default                                                                                                 => ($configuredIndex = $configuredSources->search($source, true)) === false
                    ? 100
                    : 10 + $configuredIndex,
            };

            return str_pad((string) $priority, 4, '0', STR_PAD_LEFT).':'.str_pad((string) $rate->id, 20, '0', STR_PAD_LEFT);
        })->values();
    }

    private function toResolved(ExchangeRate $rate, string $requestedDate, bool $inverted = false): ResolvedExchangeRate
    {
        $resolvedRate = $inverted
            ? BigDecimal::one()->dividedBy((string) $rate->rate, 15, RoundingMode::HalfUp)->__toString()
            : (string) $rate->rate;

        return new ResolvedExchangeRate(
            rate: $resolvedRate,
            recordId: $rate->id,
            effectiveDate: $rate->effective_date->toDateString(),
            source: $rate->source instanceof ExchangeRateSource ? $rate->source->value : (string) $rate->source,
            type: $rate->rate_type instanceof ExchangeRateType ? $rate->rate_type->value : (string) $rate->rate_type,
            usedPreviousDate: $rate->effective_date->toDateString() !== $requestedDate,
            inverted: $inverted,
        );
    }
}
