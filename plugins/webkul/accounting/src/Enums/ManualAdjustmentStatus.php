<?php

namespace Webkul\Accounting\Enums;

enum ManualAdjustmentStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
}
