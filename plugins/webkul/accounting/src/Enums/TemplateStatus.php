<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum TemplateStatus: string implements HasLabel
{
    case DRAFT = 'draft';

    case PUBLISHED = 'published';

    case ARCHIVED = 'archived';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::DRAFT     => __('accounting::enums/template-status.draft'),
            self::PUBLISHED => __('accounting::enums/template-status.published'),
            self::ARCHIVED  => __('accounting::enums/template-status.archived'),
        };
    }

    public static function options(): array
    {
        return [
            self::DRAFT->value     => __('accounting::enums/template-status.draft'),
            self::PUBLISHED->value => __('accounting::enums/template-status.published'),
            self::ARCHIVED->value  => __('accounting::enums/template-status.archived'),
        ];
    }
}
