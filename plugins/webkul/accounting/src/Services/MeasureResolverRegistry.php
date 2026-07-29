<?php

namespace Webkul\Accounting\Services;

use RuntimeException;
use Webkul\Accounting\Contracts\MeasureResolver;

/**
 * Routes a measure source key to its resolver.
 *
 * Bound as a singleton in AccountingServiceProvider with the ledger resolver
 * registered. Future sources (imported datasets, manual inputs, external APIs)
 * register here during boot without any change to the engine — the engine only
 * ever asks the registry for the resolver of a given source.
 */
class MeasureResolverRegistry
{
    /**
     * @var array<string, MeasureResolver>
     */
    protected array $resolvers = [];

    public function register(MeasureResolver $resolver): void
    {
        $this->resolvers[$resolver->source()] = $resolver;
    }

    public function has(string $source): bool
    {
        return isset($this->resolvers[$source]);
    }

    /**
     * @return array<int, string>
     */
    public function sources(): array
    {
        return array_keys($this->resolvers);
    }

    public function for(string $source): MeasureResolver
    {
        return $this->resolvers[$source]
            ?? throw new RuntimeException(
                "No measure resolver registered for source [{$source}]. Registered sources: ["
                .implode(', ', $this->sources()).'].'
            );
    }
}
