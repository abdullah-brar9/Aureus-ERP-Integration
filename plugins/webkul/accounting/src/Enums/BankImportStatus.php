<?php

namespace Webkul\Accounting\Enums;

enum BankImportStatus: string
{
    case AwaitingReview = 'awaiting_review';
    case Validated = 'validated';
    case ReconciliationFailed = 'reconciliation_failed';
    case Imported = 'imported';
    case Posted = 'posted';
    case Rejected = 'rejected';
}
