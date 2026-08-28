<?php

namespace Webkul\Accounting\Enums;

enum CashFlowCategory: string
{
    case OperatingReceipts = 'Operating - Receipts';
    case OperatingPayments = 'Operating - Payments';
    case InvestingReceipts = 'Investing - Receipts';
    case InvestingPayments = 'Investing - Payments';
    case FinancingReceipts = 'Financing - Receipts';
    case FinancingPayments = 'Financing - Payments';
    case Transfer = 'Transfer';
    case NonCash = 'Non-cash';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->value])->all();
    }
}
