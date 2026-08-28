<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum LayoutType: string implements HasLabel
{
    case PERIOD_TOTAL = 'period_total';

    case MONTHLY_MATRIX = 'monthly_matrix';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PERIOD_TOTAL   => __('accounting::enums/layout-type.period-total'),
            self::MONTHLY_MATRIX => __('accounting::enums/layout-type.monthly-matrix'),
        };
    }

    public static function options(): array
    {
        return [
            self::PERIOD_TOTAL->value   => __('accounting::enums/layout-type.period-total'),
            self::MONTHLY_MATRIX->value => __('accounting::enums/layout-type.monthly-matrix'),
        ];
    }
}
