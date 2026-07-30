<?php

namespace Webkul\Accounting\Data;

/**
 * Immutable execution context for a report run.
 *
 * Holds the company scope (one or many company IDs) and whether only posted
 * moves are considered. Passing this through every service keeps multi-company
 * behaviour explicit and avoids any service reaching for the authenticated
 * user's default company on its own.
 */
final class ReportContext
{
    /**
     * @param  array<int, int>  $companyIds
     */
    public function __construct(
        public readonly array $companyIds,
        public readonly bool $postedOnly = true,
        public readonly ?int $originalCurrencyId = null,
    ) {}

    /**
     * @param  array<int, int>  $companyIds
     */
    public static function forCompanies(array $companyIds, bool $postedOnly = true, ?int $originalCurrencyId = null): self
    {
        $normalized = array_values(array_unique(array_map('intval', $companyIds)));

        return new self($normalized, $postedOnly, $originalCurrencyId);
    }

    public static function forCompany(int $companyId, bool $postedOnly = true): self
    {
        return new self([$companyId], $postedOnly);
    }

    public static function forCompanyOriginalCurrency(int $companyId, int $currencyId, bool $postedOnly = true): self
    {
        return new self([$companyId], $postedOnly, $currencyId);
    }

    public function hasCompanyScope(): bool
    {
        return $this->companyIds !== [];
    }
}
