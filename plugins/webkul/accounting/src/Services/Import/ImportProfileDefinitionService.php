<?php

namespace Webkul\Accounting\Services\Import;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Support\Models\Company;

final class ImportProfileDefinitionService
{
    /** @return array<string, mixed> */
    public function export(ImportProfile $profile): array
    {
        $profile->refresh();
        $profile->loadMissing(['mappings', 'rules']);

        return [
            'schema_version' => 1,
            'profile'        => $profile->only([
                'name', 'entity_type', 'file_type', 'sheet_name', 'header_row', 'data_start_row', 'skip_rows',
                'blank_row_rule', 'stop_rule', 'delimiter', 'encoding', 'version',
            ]),
            'mappings' => $profile->mappings->map->only([
                'position', 'source_header', 'source_position', 'source_aliases', 'target_field',
                'transformations', 'validation_rules', 'is_required',
            ])->values()->all(),
            'rules' => $profile->rules->map->only([
                'name', 'entity_type', 'priority', 'effective_from', 'effective_until', 'conditions',
                'actions', 'stop_processing', 'is_active',
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $definition */
    public function import(Company $company, array $definition, int $ownerId, ?string $name = null): ImportProfile
    {
        if (($definition['schema_version'] ?? null) !== 1 || ! is_array($definition['profile'] ?? null) || ! is_array($definition['mappings'] ?? null)) {
            throw new InvalidArgumentException('The import profile definition is invalid or unsupported.');
        }

        $profileData = $definition['profile'];
        $profileData['name'] = trim($name ?: (string) ($profileData['name'] ?? ''));
        if ($profileData['name'] === '' || ! app(ImportEntityRegistry::class)->supports((string) ($profileData['entity_type'] ?? ''))) {
            throw new InvalidArgumentException('The profile name or entity type is invalid.');
        }
        if (! in_array($profileData['file_type'] ?? null, ['csv', 'xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('The profile file type is invalid.');
        }

        return DB::transaction(function () use ($company, $ownerId, $profileData, $definition): ImportProfile {
            $version = ((int) ImportProfile::query()->where('company_id', $company->id)->where('name', $profileData['name'])->max('version')) + 1;
            $profile = ImportProfile::query()->create(array_merge($profileData, [
                'company_id' => $company->id, 'owner_id' => $ownerId, 'version' => $version,
                'is_active'  => false, 'activated_at' => null,
            ]));
            foreach ($definition['mappings'] as $mapping) {
                if (! is_array($mapping) || ! array_key_exists((string) ($mapping['target_field'] ?? ''), app(ImportEntityRegistry::class)->fields($profile->entity_type))) {
                    throw new InvalidArgumentException('A profile mapping target is invalid.');
                }
                $profile->mappings()->create($mapping);
            }
            foreach ((array) ($definition['rules'] ?? []) as $rule) {
                if (is_array($rule)) {
                    $profile->rules()->create($rule + ['company_id' => $company->id, 'creator_id' => $ownerId, 'entity_type' => $profile->entity_type]);
                }
            }

            return $profile->fresh(['mappings', 'rules']);
        });
    }
}
