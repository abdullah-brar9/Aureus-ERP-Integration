<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum LineType: string implements HasLabel
{
    case SECTION_HEADER = 'section_header';

    case DETAIL = 'detail';

    case SUBTOTAL = 'subtotal';

    case SPACER = 'spacer';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::SECTION_HEADER => __('accounting::enums/line-type.section-header'),
            self::DETAIL         => __('accounting::enums/line-type.detail'),
            self::SUBTOTAL       => __('accounting::enums/line-type.subtotal'),
            self::SPACER         => __('accounting::enums/line-type.spacer'),
        };
    }

    public static function options(): array
    {
        return [
            self::SECTION_HEADER->value => __('accounting::enums/line-type.section-header'),
            self::DETAIL->value         => __('accounting::enums/line-type.detail'),
            self::SUBTOTAL->value       => __('accounting::enums/line-type.subtotal'),
            self::SPACER->value         => __('accounting::enums/line-type.spacer'),
        ];
    }
}
