<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormulaOperandType: string implements HasLabel
{
    case LINE = 'line';

    case CONSTANT = 'constant';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LINE     => __('accounting::enums/formula-operand-type.line'),
            self::CONSTANT => __('accounting::enums/formula-operand-type.constant'),
        };
    }

    public static function options(): array
    {
        return [
            self::LINE->value     => __('accounting::enums/formula-operand-type.line'),
            self::CONSTANT->value => __('accounting::enums/formula-operand-type.constant'),
        ];
    }
}
