<?php

namespace Webkul\Accounting\Enums;

enum BankPostingStatus: string
{
    case NotPosted = 'not_posted';
    case Draft = 'draft';
    case Review = 'review';
    case Posted = 'posted';
    case MatchedDoNotPost = 'matched_do_not_post';
    case DoNotPost = 'do_not_post';
}
