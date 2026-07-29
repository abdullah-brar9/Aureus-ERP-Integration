<?php

namespace Webkul\Accounting\Services\Bank;

use RuntimeException;
use Webkul\Accounting\Contracts\BankStatementParser;

class BankStatementParserRegistry
{
    /** @var array<string, BankStatementParser> */
    protected array $parsers = [];

    public function register(BankStatementParser $parser): void
    {
        $this->parsers[$parser->key()] = $parser;
    }

    public function resolve(string $path, ?string $key = null, ?string $sheetName = null): BankStatementParser
    {
        if ($key !== null) {
            return $this->parsers[$key] ?? throw new RuntimeException("Unknown bank statement parser: {$key}");
        }

        foreach ($this->parsers as $parser) {
            if ($parser->supports($path, $sheetName)) {
                return $parser;
            }
        }

        throw new RuntimeException('No reliable parser recognizes this bank statement.');
    }

    public function options(): array
    {
        return array_combine(array_keys($this->parsers), array_map('strtoupper', array_keys($this->parsers)));
    }
}
