<?php

namespace Webkul\Accounting\Services\Import;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ImportProfileMapping;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Support\Models\Currency;

final class ImportPreviewService
{
    public function __construct(
        private readonly TabularFileReader $reader,
        private readonly ImportTransformationEngine $transformations,
        private readonly ConditionalRuleEngine $rules,
        private readonly ImportEntityRegistry $entities,
    ) {}

    public function preview(ImportProfile $profile, string $path, string $originalFilename, ?int $userId = null): ImportRun
    {
        if (! $profile->is_active) {
            throw new \RuntimeException('Only an active import profile can be used.');
        }
        if (! $this->entities->supports($profile->entity_type)) {
            throw new \RuntimeException("Unsupported import entity [{$profile->entity_type}].");
        }

        $profile->loadMissing('mappings');
        $file = $this->reader->read($path, $profile);
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException('The import file could not be hashed.');
        }

        $effectiveRules = BusinessRule::effective($profile->company_id, $profile->entity_type)
            ->where(fn ($query) => $query->whereNull('profile_id')->orWhere('profile_id', $profile->id))
            ->get();

        return DB::transaction(function () use ($profile, $file, $hash, $path, $originalFilename, $userId, $effectiveRules): ImportRun {
            $run = ImportRun::query()->create([
                'company_id'        => $profile->company_id,
                'profile_id'        => $profile->id,
                'imported_by_id'    => $userId,
                'reference'         => 'IMP-'.Str::upper(Str::random(14)),
                'status'            => 'previewed',
                'original_filename' => basename($originalFilename),
                'file_hash'         => $hash,
                'source_sheet'      => $file['sheet'],
                'profile_version'   => $profile->version,
            ]);

            $counts = ['pass' => 0, 'warning' => 0, 'error' => 0];
            foreach ($file['rows'] as $source) {
                [$values, $messages] = $this->mapAndValidate($profile, $file['headers'], $source['values'], $effectiveRules);
                $status = collect($messages)->contains(fn (array $message): bool => $message['severity'] === 'error')
                    ? 'error'
                    : (collect($messages)->contains(fn (array $message): bool => $message['severity'] === 'warning') ? 'warning' : 'pass');
                $counts[$status]++;

                $run->sourceRows()->create([
                    'company_id'         => $profile->company_id,
                    'source_row_number'  => $source['row_number'],
                    'status'             => $status,
                    'raw_values'         => array_combine($file['headers'], array_pad($source['values'], count($file['headers']), null)),
                    'transformed_values' => $values,
                    'messages'           => $messages,
                ]);
            }

            $run->update([
                'total_rows'   => count($file['rows']),
                'passed_rows'  => $counts['pass'],
                'warning_rows' => $counts['warning'],
                'failed_rows'  => $counts['error'],
                'summary'      => [
                    'headers'         => $file['headers'],
                    'profile_name'    => $profile->name,
                    'entity_type'     => $profile->entity_type,
                    'profile_version' => $profile->version,
                    'staged_path'     => $path,
                ],
            ]);

            return $run->fresh(['sourceRows']);
        });
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $sourceValues
     * @param  Collection<int, BusinessRule>  $effectiveRules
     * @return array{0: array<string, mixed>, 1: array<int, array{severity: string, field?: string, message: string, rule_id?: int}>}
     */
    private function mapAndValidate(ImportProfile $profile, array $headers, array $sourceValues, $effectiveRules): array
    {
        $messages = [];
        $values = [];
        $normalizedHeaders = array_map($this->normalizeHeader(...), $headers);

        foreach ($profile->mappings as $mapping) {
            $raw = $this->sourceValue($mapping, $normalizedHeaders, $sourceValues);
            try {
                $values[$mapping->target_field] = $this->transformations->transform($raw, (array) $mapping->transformations, $values);
            } catch (Throwable $exception) {
                $values[$mapping->target_field] = $raw;
                $messages[] = ['severity' => 'error', 'field' => $mapping->target_field, 'message' => $exception->getMessage()];
            }

            if ($mapping->is_required && (($values[$mapping->target_field] ?? null) === null || ($values[$mapping->target_field] ?? '') === '')) {
                $messages[] = ['severity' => 'error', 'field' => $mapping->target_field, 'message' => 'A required value is missing.'];
            }

            $messages = [...$messages, ...$this->validateMappedValue($mapping, $values[$mapping->target_field] ?? null)];
        }

        try {
            $result = $this->rules->apply($values, $effectiveRules);
            $values = $result['values'];
            foreach ($result['applied_rule_ids'] as $ruleId) {
                $messages[] = ['severity' => 'warning', 'message' => 'A configured business rule changed this row.', 'rule_id' => $ruleId];
            }
        } catch (Throwable $exception) {
            $messages[] = ['severity' => 'error', 'message' => $exception->getMessage()];
        }

        foreach ($this->entities->requiredFields($profile->entity_type) as $required) {
            if (($values[$required] ?? null) === null || ($values[$required] ?? '') === '') {
                $messages[] = ['severity' => 'error', 'field' => $required, 'message' => 'This canonical field is required.'];
            }
        }

        $messages = [...$messages, ...$this->validateReferences($profile, $values)];

        return [$values, $messages];
    }

    /** @param array<int, string> $normalizedHeaders @param array<int, mixed> $sourceValues */
    private function sourceValue(ImportProfileMapping $mapping, array $normalizedHeaders, array $sourceValues): mixed
    {
        if ($mapping->source_position !== null) {
            return $sourceValues[max(0, $mapping->source_position - 1)] ?? null;
        }

        $candidates = array_filter([$mapping->source_header, ...(array) $mapping->source_aliases]);
        foreach ($candidates as $candidate) {
            $index = array_search($this->normalizeHeader((string) $candidate), $normalizedHeaders, true);
            if ($index !== false) {
                return $sourceValues[$index] ?? null;
            }
        }

        return null;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)->squish()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }

    /** @param array<string, mixed> $values @return array<int, array{severity: string, field?: string, message: string}> */
    private function validateReferences(ImportProfile $profile, array $values): array
    {
        $messages = [];
        if (! empty($values['currency']) && ! Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['currency'])])->exists()) {
            $messages[] = ['severity' => 'error', 'field' => 'currency', 'message' => 'The currency code does not exist.'];
        }

        if (in_array($profile->entity_type, ['invoice', 'bill', 'claim'], true) && ! empty($values['partner_reference'])) {
            $exists = Partner::query()
                ->where('company_id', $profile->company_id)
                ->where('reference', $values['partner_reference'])
                ->exists();
            if (! $exists) {
                $messages[] = ['severity' => 'error', 'field' => 'partner_reference', 'message' => 'The party reference does not exist in this company.'];
            }
        }

        if (in_array($profile->entity_type, ['invoice', 'bill', 'claim', 'miscellaneous'], true) && ! empty($values['journal_code'])) {
            $exists = Journal::query()
                ->where('company_id', $profile->company_id)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
                ->exists();
            if (! $exists) {
                $messages[] = ['severity' => 'error', 'field' => 'journal_code', 'message' => 'The journal code does not exist in this company.'];
            }
        }
        if (in_array($profile->entity_type, ['invoice', 'bill', 'claim', 'miscellaneous'], true)) {
            foreach (['debit_gl_code', 'credit_gl_code'] as $field) {
                if (! empty($values[$field]) && ! Account::query()->postable()->where('deprecated', false)
                    ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values[$field])])
                    ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))->exists()) {
                    $messages[] = ['severity' => 'error', 'field' => $field, 'message' => 'The GL code is not an active postable account in this company.'];
                }
            }
        }

        if ($profile->entity_type === 'bank_statement') {
            if (! empty($values['journal_code']) && ! Journal::query()
                ->where('company_id', $profile->company_id)
                ->where('type', JournalType::BANK)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'journal_code', 'message' => 'The bank journal code does not exist in this company.'];
            }
            if (! empty($values['bank_gl_code']) && ! Account::query()
                ->postable()->where('deprecated', false)->where('account_type', AccountType::ASSET_CASH)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['bank_gl_code'])])
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $profile->company_id))
                ->exists()) {
                $messages[] = ['severity' => 'error', 'field' => 'bank_gl_code', 'message' => 'The Bank GL code is not an active company-owned Bank/Cash account.'];
            }
        }

        return $messages;
    }

    /** @return array<int, array{severity: string, field: string, message: string}> */
    private function validateMappedValue(ImportProfileMapping $mapping, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $messages = [];
        foreach ((array) $mapping->validation_rules as $rule) {
            $rule = (string) $rule;
            $valid = match ($rule) {
                'email'   => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'date'    => $this->isDate($value),
                'numeric' => $this->isDecimal($value),
                'boolean' => is_bool($value) || in_array(mb_strtolower((string) $value), ['0', '1', 'true', 'false', 'yes', 'no'], true),
                default   => false,
            };
            if (! $valid) {
                $messages[] = ['severity' => 'error', 'field' => $mapping->target_field, 'message' => "The value failed the {$rule} check."];
            }
        }

        return $messages;
    }

    private function isDate(mixed $value): bool
    {
        try {
            CarbonImmutable::parse((string) $value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isDecimal(mixed $value): bool
    {
        try {
            BigDecimal::of((string) $value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
