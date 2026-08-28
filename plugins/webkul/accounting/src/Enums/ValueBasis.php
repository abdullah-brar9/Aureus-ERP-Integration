<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ValueBasis: string implements HasLabel
{
    case MOVEMENT = 'movement';

    case OPENING_BALANCE = 'opening_balance';

    case CLOSING_BALANCE = 'closing_balance';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MOVEMENT        => __('accounting::enums/value-basis.movement'),
            self::OPENING_BALANCE => __('accounting::enums/value-basis.opening-balance'),
            self::CLOSING_BALANCE => __('accounting::enums/value-basis.closing-balance'),
        };
    }

    public static function options(): array
    {
        return [
            self::MOVEMENT->value        => __('accounting::enums/value-basis.movement'),
            self::OPENING_BALANCE->value => __('accounting::enums/value-basis.opening-balance'),
            self::CLOSING_BALANCE->value => __('accounting::enums/value-basis.closing-balance'),
        ];
    }
}
