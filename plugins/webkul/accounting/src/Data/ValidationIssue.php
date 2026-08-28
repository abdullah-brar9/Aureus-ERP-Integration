<?php

namespace Webkul\Accounting\Data;

/**
 * One problem found while validating a report template (or its computed
 * results). `code` is a stable machine identifier; `message` is the translated
 * human explanation the designer UI will surface.
 */
final class ValidationIssue
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $severity = self::SEVERITY_ERROR,
        public readonly ?int $lineId = null,
        public readonly ?int $columnId = null,
    ) {}

    public static function error(string $code, string $message, ?int $lineId = null, ?int $columnId = null): self
    {
        return new self($code, $message, self::SEVERITY_ERROR, $lineId, $columnId);
    }

    public static function warning(string $code, string $message, ?int $lineId = null, ?int $columnId = null): self
    {
        return new self($code, $message, self::SEVERITY_WARNING, $lineId, $columnId);
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    public function toArray(): array
    {
        return [
            'code'      => $this->code,
            'message'   => $this->message,
            'severity'  => $this->severity,
            'line_id'   => $this->lineId,
            'column_id' => $this->columnId,
        ];
    }
}
