<?php

namespace Webkul\Accounting\Enums;

enum ExchangeRateType: string
{
    case Transaction = 'transaction';
    case Daily = 'daily';
    case MonthlyAverage = 'monthly_average';
    case PeriodClosing = 'period_closing';
    case BankProvided = 'bank_provided';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [
            $type->value => str($type->name)->headline()->toString(),
        ])->all();
    }
}
