<?php

namespace Webkul\Accounting\Contracts;

use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

interface ExchangeRateProvider
{
    public function name(): string;

    /**
     * @return array{rate: string, source_reference: ?string, metadata: array<string, mixed>}|null
     */
    public function fetch(
        Company $company,
        Currency $sourceCurrency,
        Currency $targetCurrency,
        string $effectiveDate,
        string $rateType,
    ): ?array;
}
