<?php

namespace Webkul\Accounting\Data\Coa;

/**
 * A row- or file-level issue surfaced during CoA validation. Errors block the
 * import; warnings are flagged for the user to acknowledge but do not block
 * (the spec requires flagging suspicious data without silently changing it).
 */
final class CoaWarning
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $severity = self::WARNING,
        public readonly ?int $sheetRow = null,
        public readonly ?string $accountCode = null,
    ) {}

    public static function error(string $code, string $message, ?int $sheetRow = null, ?string $accountCode = null): self
    {
        return new self($code, $message, self::ERROR, $sheetRow, $accountCode);
    }

    public static function warning(string $code, string $message, ?int $sheetRow = null, ?string $accountCode = null): self
    {
        return new self($code, $message, self::WARNING, $sheetRow, $accountCode);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code'         => $this->code,
            'message'      => $this->message,
            'severity'     => $this->severity,
            'sheet_row'    => $this->sheetRow,
            'account_code' => $this->accountCode,
        ];
    }
}
