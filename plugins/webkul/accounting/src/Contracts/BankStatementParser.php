<?php

namespace Webkul\Accounting\Contracts;

use Webkul\Accounting\Data\Bank\NormalizedBankStatement;

interface BankStatementParser
{
    public function key(): string;

    public function supports(string $path, ?string $sheetName = null): bool;

    public function parse(string $path, ?string $sheetName = null): NormalizedBankStatement;
}
