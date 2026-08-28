<?php

namespace Webkul\Accounting\Services\Bank;

class BankDescriptionNormalizer
{
    public function normalize(?string $description): string
    {
        $value = mb_strtolower(trim((string) $description));
        $value = preg_replace('/\b\d{6,}\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
