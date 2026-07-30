<?php

namespace Webkul\Accounting\Enums;

enum ExchangeRateSource: string
{
    case BankStatement = 'bank_statement';
    case Manual = 'manual';
    case Api = 'api';
    case ImportedFile = 'imported_file';
    case Identity = 'identity';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $source): array => [
            $source->value => str($source->name)->headline()->toString(),
        ])->all();
    }
}
