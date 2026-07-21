<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ValueSource: string implements HasLabel
{
    case LEDGER = 'ledger';

    case FORMULA = 'formula';

    case MANUAL = 'manual';

    case EXTERNAL = 'external';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LEDGER   => __('accounting::enums/value-source.ledger'),
            self::FORMULA  => __('accounting::enums/value-source.formula'),
            self::MANUAL   => __('accounting::enums/value-source.manual'),
            self::EXTERNAL => __('accounting::enums/value-source.external'),
        };
    }

    public static function options(): array
    {
        return [
            self::LEDGER->value   => __('accounting::enums/value-source.ledger'),
            self::FORMULA->value  => __('accounting::enums/value-source.formula'),
            self::MANUAL->value   => __('accounting::enums/value-source.manual'),
            self::EXTERNAL->value => __('accounting::enums/value-source.external'),
        ];
    }
}
