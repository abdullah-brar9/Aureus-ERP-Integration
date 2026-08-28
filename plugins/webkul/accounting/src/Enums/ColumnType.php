<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ColumnType: string implements HasLabel
{
    case MONTH = 'month';

    case RANGE = 'range';

    case FULL_YEAR = 'full_year';

    case SPACER = 'spacer';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MONTH     => __('accounting::enums/column-type.month'),
            self::RANGE     => __('accounting::enums/column-type.range'),
            self::FULL_YEAR => __('accounting::enums/column-type.full-year'),
            self::SPACER    => __('accounting::enums/column-type.spacer'),
        };
    }

    public static function options(): array
    {
        return [
            self::MONTH->value     => __('accounting::enums/column-type.month'),
            self::RANGE->value     => __('accounting::enums/column-type.range'),
            self::FULL_YEAR->value => __('accounting::enums/column-type.full-year'),
            self::SPACER->value    => __('accounting::enums/column-type.spacer'),
        ];
    }
}
