<?php

namespace Webkul\Accounting\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case MissingRate = 'missing_rate';
    case ReviewRequired = 'review_required';
    case Provisional = 'provisional';
}
