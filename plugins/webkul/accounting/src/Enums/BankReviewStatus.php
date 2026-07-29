<?php

namespace Webkul\Accounting\Enums;

enum BankReviewStatus: string
{
    case Unmapped = 'unmapped';
    case Suggested = 'suggested';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case MatchedTransfer = 'matched_transfer';
    case DoNotPost = 'do_not_post';

    public static function options(): array
    {
        return [
            self::Unmapped->value        => 'Unmapped',
            self::Suggested->value       => 'Suggested',
            self::NeedsReview->value     => 'Needs Review',
            self::Approved->value        => 'Approved',
            self::Rejected->value        => 'Rejected',
            self::Posted->value          => 'Posted',
            self::MatchedTransfer->value => 'Matched Transfer',
            self::DoNotPost->value       => 'Do Not Post',
        ];
    }
}
