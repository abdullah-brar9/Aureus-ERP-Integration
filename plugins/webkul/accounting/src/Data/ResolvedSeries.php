<?php

namespace Webkul\Accounting\Data;

/**
 * The result of resolving a batch of measures: value per measure key per
 * period, i.e. [key][period_key] => value.
 *
 * This is deliberately the exact shape LedgerBalanceRepository::basisBalances
 * already returns ([account_id][period_key] => balance), so wrapping the
 * repository in a resolver is a zero-transform pass-through.
 */
final class ResolvedSeries
{
    /**
     * @param  array<int|string, array<string, float>>  $values  key => [period_key => value]
     */
    public function __construct(
        private readonly array $values = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function valueFor(int|string $key, string $periodKey): float
    {
        return (float) ($this->values[$key][$periodKey] ?? 0.0);
    }

    /**
     * The raw [key][period_key] => value map.
     *
     * @return array<int|string, array<string, float>>
     */
    public function all(): array
    {
        return $this->values;
    }
}
