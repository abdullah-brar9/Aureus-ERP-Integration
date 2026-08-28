<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormulaPurpose: string implements HasLabel
{
    case VALUE = 'value';

    case CONSOLIDATION = 'consolidation';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::VALUE         => __('accounting::enums/formula-purpose.value'),
            self::CONSOLIDATION => __('accounting::enums/formula-purpose.consolidation'),
        };
    }

    public static function options(): array
    {
        return [
            self::VALUE->value         => __('accounting::enums/formula-purpose.value'),
            self::CONSOLIDATION->value => __('accounting::enums/formula-purpose.consolidation'),
        ];
    }
}
