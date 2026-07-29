<?php

namespace Webkul\Accounting\Enums;

enum ReportCompletenessStatus: string
{
    case Complete = 'Complete';
    case BankDerived = 'Bank-derived / provisional';
    case MissingOpeningBalances = 'Missing opening balances';
    case MissingNonBankAdjustments = 'Missing non-bank adjustments';
    case AwaitingReview = 'Awaiting review';
}
