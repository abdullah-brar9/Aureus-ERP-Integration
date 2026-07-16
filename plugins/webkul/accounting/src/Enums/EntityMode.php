<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum EntityMode: string implements HasLabel
{
    case SINGLE_COMPANY = 'single_company';

    case MULTI_COMPANY_CONSOLIDATED = 'multi_company_consolidated';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::SINGLE_COMPANY             => __('accounting::enums/entity-mode.single-company'),
            self::MULTI_COMPANY_CONSOLIDATED => __('accounting::enums/entity-mode.multi-company-consolidated'),
        };
    }

    public static function options(): array
    {
        return [
            self::SINGLE_COMPANY->value             => __('accounting::enums/entity-mode.single-company'),
            self::MULTI_COMPANY_CONSOLIDATED->value => __('accounting::enums/entity-mode.multi-company-consolidated'),
        ];
    }
}
