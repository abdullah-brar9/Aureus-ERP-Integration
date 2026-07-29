<?php

namespace Webkul\Accounting\Services\Bank;

class MeezanBankStatementParser extends AbstractSpreadsheetBankStatementParser
{
    public function key(): string
    {
        return 'meezan';
    }

    protected function bankName(): string
    {
        return 'Meezan Bank';
    }

    protected function detectionToken(): string
    {
        return 'Meezan';
    }
}
