<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormulaOperator: string implements HasLabel
{
    case ADD = '+';

    case SUBTRACT = '-';

    case MULTIPLY = '*';

    case DIVIDE = '/';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ADD      => __('accounting::enums/formula-operator.add'),
            self::SUBTRACT => __('accounting::enums/formula-operator.subtract'),
            self::MULTIPLY => __('accounting::enums/formula-operator.multiply'),
            self::DIVIDE   => __('accounting::enums/formula-operator.divide'),
        };
    }

    public static function options(): array
    {
        return [
            self::ADD->value      => __('accounting::enums/formula-operator.add'),
            self::SUBTRACT->value => __('accounting::enums/formula-operator.subtract'),
            self::MULTIPLY->value => __('accounting::enums/formula-operator.multiply'),
            self::DIVIDE->value   => __('accounting::enums/formula-operator.divide'),
        ];
    }
}
