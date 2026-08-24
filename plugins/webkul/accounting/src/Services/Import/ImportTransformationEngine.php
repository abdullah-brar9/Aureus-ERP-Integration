<?php

namespace Webkul\Accounting\Services\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

final class ImportTransformationEngine
{
    private const ALLOWED = [
        'trim', 'upper', 'lower', 'title', 'date', 'decimal', 'boolean', 'null_if', 'default',
        'map', 'concat', 'split', 'find_replace', 'regex_replace',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $row
     */
    public function transform(mixed $value, array $steps, array $row = []): mixed
    {
        foreach ($steps as $step) {
            $type = (string) ($step['type'] ?? '');
            if (! in_array($type, self::ALLOWED, true)) {
                throw new InvalidArgumentException("Unsupported transformation [{$type}].");
            }

            $value = match ($type) {
                'trim'          => is_string($value) ? trim($value) : $value,
                'upper'         => is_string($value) ? mb_strtoupper($value) : $value,
                'lower'         => is_string($value) ? mb_strtolower($value) : $value,
                'title'         => is_string($value) ? Str::title($value) : $value,
                'date'          => $this->date($value, $step),
                'decimal'       => $this->decimal($value, $step),
                'boolean'       => $this->boolean($value, $step),
                'null_if'       => in_array($value, (array) ($step['values'] ?? []), true) ? null : $value,
                'default'       => $value === null || $value === '' ? ($step['value'] ?? null) : $value,
                'map'           => ((array) ($step['values'] ?? []))[(string) $value] ?? ($step['default'] ?? $value),
                'concat'        => $this->concat($value, $step, $row),
                'split'         => $this->split($value, $step),
                'find_replace'  => is_string($value) ? str_replace((string) ($step['find'] ?? ''), (string) ($step['replace'] ?? ''), $value) : $value,
                'regex_replace' => $this->safeRegexReplace($value, $step),
            };
        }

        return $value;
    }

    /** @param array<string, mixed> $step */
    private function date(mixed $value, array $step): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $date = CarbonImmutable::instance(SpreadsheetDate::excelToDateTimeObject((float) $value));
        } else {
            $format = $step['format'] ?? null;
            $date = $format
                ? CarbonImmutable::createFromFormat((string) $format, (string) $value)
                : CarbonImmutable::parse((string) $value);
        }

        return $date->format((string) ($step['output'] ?? 'Y-m-d'));
    }

    /** @param array<string, mixed> $step */
    private function decimal(mixed $value, array $step): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decimalSeparator = (string) ($step['decimal_separator'] ?? '.');
        $thousandsSeparator = (string) ($step['thousands_separator'] ?? ',');
        $normalized = str_replace($thousandsSeparator, '', trim((string) $value));
        $normalized = $decimalSeparator === '.' ? $normalized : str_replace($decimalSeparator, '.', $normalized);

        return BigDecimal::of($normalized)->toScale((int) ($step['scale'] ?? 4), RoundingMode::HalfUp)->__toString();
    }

    /** @param array<string, mixed> $step */
    private function boolean(mixed $value, array $step): bool
    {
        $truthy = array_map('mb_strtolower', (array) ($step['true_values'] ?? ['1', 'true', 'yes', 'y']));

        return in_array(mb_strtolower(trim((string) $value)), $truthy, true);
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $row
     */
    private function concat(mixed $value, array $step, array $row): string
    {
        $values = [$value];
        foreach ((array) ($step['fields'] ?? []) as $field) {
            $values[] = $row[(string) $field] ?? null;
        }

        return implode((string) ($step['separator'] ?? ' '), array_filter($values, fn (mixed $part): bool => $part !== null && $part !== ''));
    }

    /** @param array<string, mixed> $step */
    private function split(mixed $value, array $step): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return explode((string) ($step['separator'] ?? ' '), $value)[(int) ($step['index'] ?? 0)] ?? null;
    }

    /** @param array<string, mixed> $step */
    private function safeRegexReplace(mixed $value, array $step): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $pattern = (string) ($step['pattern'] ?? '');
        $delimiter = $pattern[0] ?? '';
        $lastDelimiter = $delimiter === '' ? false : strrpos($pattern, $delimiter);
        $modifiers = $lastDelimiter === false ? '' : substr($pattern, $lastDelimiter + 1);
        $hasNestedQuantifier = preg_match('/\([^)]*[+*][^)]*\)[+*{]/', $pattern) === 1;
        if ($pattern === '' || mb_strlen($pattern) > 200 || str_contains($modifiers, 'e') || $hasNestedQuantifier) {
            throw new InvalidArgumentException('The regular expression is empty or unsafe.');
        }

        $result = @preg_replace($pattern, (string) ($step['replacement'] ?? ''), $value, 1000);
        if ($result === null) {
            throw new InvalidArgumentException('The regular expression is invalid.');
        }

        return $result;
    }
}
