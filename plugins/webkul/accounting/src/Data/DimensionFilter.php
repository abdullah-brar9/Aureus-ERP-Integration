<?php

namespace Webkul\Accounting\Data;

/**
 * An immutable set of dimension constraints applied to a measure request
 * (e.g. ["vertical" => "Fulfillment"]).
 *
 * Phase 0 placeholder: the ledger resolver does not consume dimensions yet
 * (its axes — company scope, posted state — live on ResolutionContext), so the
 * filter is empty in every current call. It exists now so the resolver
 * contract is dimension-aware from the start and future dataset adapters need
 * no signature change.
 */
final class DimensionFilter
{
    /**
     * @param  array<string, mixed>  $dimensions  dimension name => required value(s)
     */
    public function __construct(
        public readonly array $dimensions = [],
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->dimensions === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->dimensions;
    }
}
