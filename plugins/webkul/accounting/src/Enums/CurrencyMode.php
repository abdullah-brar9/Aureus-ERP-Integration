<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum CurrencyMode: string implements HasLabel
{
    case LEDGER_ONLY = 'ledger_only';

    case USD_ONLY = 'usd_only';

    case LEDGER_AND_USD = 'ledger_and_usd';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LEDGER_ONLY    => __('accounting::enums/currency-mode.ledger-only'),
            self::USD_ONLY       => __('accounting::enums/currency-mode.usd-only'),
            self::LEDGER_AND_USD => __('accounting::enums/currency-mode.ledger-and-usd'),
        };
    }

    public static function options(): array
    {
        return [
            self::LEDGER_ONLY->value    => __('accounting::enums/currency-mode.ledger-only'),
            self::USD_ONLY->value       => __('accounting::enums/currency-mode.usd-only'),
            self::LEDGER_AND_USD->value => __('accounting::enums/currency-mode.ledger-and-usd'),
        ];
    }
}
