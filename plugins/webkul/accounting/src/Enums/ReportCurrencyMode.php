<?php

namespace Webkul\Accounting\Enums;

enum ReportCurrencyMode: string
{
    case Company = 'company';
    case Original = 'original';
    case Reporting = 'reporting';

    public static function options(): array
    {
        return [
            self::Company->value   => 'Company default currency',
            self::Original->value  => 'Original transaction currency',
            self::Reporting->value => 'Selected reporting currency',
        ];
    }
}
