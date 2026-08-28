<?php

namespace Webkul\Accounting\Services\Import;

use Webkul\Accounting\Contracts\BankStatementParser;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;

final class ProfileBankStatementParser implements BankStatementParser
{
    public function __construct(
        private readonly string $parserKey,
        private readonly NormalizedBankStatement $statement,
    ) {}

    public function key(): string
    {
        return $this->parserKey;
    }

    public function supports(string $path, ?string $sheetName = null): bool
    {
        return true;
    }

    public function parse(string $path, ?string $sheetName = null): NormalizedBankStatement
    {
        return $this->statement;
    }
}
