<?php

namespace Webkul\Accounting\Enums;

enum ExchangeRateApprovalStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [
            $status->value => str($status->name)->headline()->toString(),
        ])->all();
    }
}
