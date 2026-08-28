<?php

namespace Webkul\Support\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = base_path('plugins/webkul/security/src/Data/countries.json');
        $currencyPath = base_path('plugins/webkul/security/src/Data/currencies.json');

        if (File::exists($path) && File::exists($currencyPath)) {
            $countries = json_decode(File::get($path), true);
            $legacyCurrencies = array_values(json_decode(File::get($currencyPath), true));
            $currencyIdsByCode = DB::table('currencies')
                ->whereNotNull('code')
                ->pluck('id', 'code');

            $currencyCodeAliases = [
                'ANG' => 'XCG',
                'BGN' => 'EUR',
                'STD' => 'STN',
                'VEF' => 'VES',
                'ZIG' => 'ZWG',
            ];

            $formattedCountries = collect($countries)->values()->map(function ($country, int $index) use ($currencyCodeAliases, $currencyIdsByCode, $legacyCurrencies) {
                $legacyCurrencyId = (int) ($country['currency_id'] ?? 0);
                $legacyCurrencyCode = mb_strtoupper((string) ($legacyCurrencies[$legacyCurrencyId - 1]['name'] ?? ''));
                $currencyCode = $currencyCodeAliases[$legacyCurrencyCode] ?? $legacyCurrencyCode;
                $currencyId = $currencyIdsByCode->get($currencyCode);

                if (! $currencyId) {
                    throw new RuntimeException("Canonical currency {$currencyCode} is missing for {$country['name']}.");
                }

                return [
                    'id'             => $index + 1,
                    'currency_id'    => (int) $currencyId,
                    'phone_code'     => (int) $country['phone_code'] ?? null,
                    'code'           => $country['code'] ?? null,
                    'name'           => $country['name'] ?? null,
                    'state_required' => (bool) $country['state_required'],
                    'zip_required'   => (bool) $country['zip_required'],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            })->toArray();

            DB::table('countries')->upsert(
                $formattedCountries,
                ['id'],
                [
                    'currency_id',
                    'phone_code',
                    'code',
                    'name',
                    'state_required',
                    'zip_required',
                    'updated_at',
                ],
            );
        }
    }
}
