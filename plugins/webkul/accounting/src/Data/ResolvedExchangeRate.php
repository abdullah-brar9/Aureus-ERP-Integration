<?php

namespace Webkul\Accounting\Data;

readonly class ResolvedExchangeRate
{
    public function __construct(
        public string $rate,
        public ?int $recordId,
        public string $effectiveDate,
        public string $source,
        public string $type,
        public bool $usedPreviousDate = false,
        public bool $inverted = false,
    ) {}
}
