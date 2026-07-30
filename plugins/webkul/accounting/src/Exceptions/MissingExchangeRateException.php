<?php

namespace Webkul\Accounting\Exceptions;

use RuntimeException;

class MissingExchangeRateException extends RuntimeException
{
    public static function forPair(string $sourceCode, string $targetCode, string $date, string $type): self
    {
        return new self("No approved {$type} exchange rate exists for {$sourceCode} to {$targetCode} on {$date}.");
    }
}
