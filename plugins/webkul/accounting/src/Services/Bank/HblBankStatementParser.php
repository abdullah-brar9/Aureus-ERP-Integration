<?php

namespace Webkul\Accounting\Services\Bank;

class HblBankStatementParser extends AbstractSpreadsheetBankStatementParser
{
    public function key(): string
    {
        return 'hbl';
    }

    protected function bankName(): string
    {
        return 'HBL';
    }

    protected function detectionToken(): string
    {
        return 'HBL';
    }
}
