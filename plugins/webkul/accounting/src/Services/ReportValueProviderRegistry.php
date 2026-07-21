<?php

namespace Webkul\Accounting\Services;

use Closure;
use RuntimeException;
use Webkul\Accounting\Contracts\ReportValueProvider;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Models\ReportLine;

/**
 * Registry of external value providers, keyed by the string stored in a
 * report line's `external_provider` column.
 *
 * Bound as a singleton in AccountingServiceProvider so other plugins (or the
 * app) can register providers during boot:
 *
 *     app(ReportValueProviderRegistry::class)->register('parcels', new ParcelCountProvider());
 */
class ReportValueProviderRegistry
{
    /**
     * @var array<string, ReportValueProvider|Closure>
     */
    protected array $providers = [];

    public function register(string $key, ReportValueProvider|Closure $provider): void
    {
        $this->providers[$key] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function value(string $key, ReportLine $line, ReportPeriod $period, ReportContext $context): float
    {
        $provider = $this->providers[$key] ?? null;

        if ($provider === null) {
            throw new RuntimeException(
                "No report value provider registered for key [{$key}] (report line {$line->id})."
            );
        }

        if ($provider instanceof Closure) {
            return (float) $provider($line, $period, $context);
        }

        return $provider->value($line, $period, $context);
    }
}
