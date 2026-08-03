<?php

namespace Webkul\Support\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (
            Schema::hasColumn('currencies', 'code')
            && DB::table('currencies')->whereNotNull('code')->exists()
        ) {
            return;
        }

        $path = base_path('plugins/webkul/security/src/Data/currencies.json');

        if (File::exists($path)) {
            $currencies = json_decode(File::get($path), true);
            $hasCodeColumn = Schema::hasColumn('currencies', 'code');

            $currencies = collect($currencies)->values()->map(function ($currency, int $index) use ($hasCodeColumn) {
                $currency['id'] = $index + 1;
                $currency['iso_numeric'] = (int) ($currency['iso_numeric'] ?? null);
                $currency['decimal_places'] = (int) ($currency['decimal_places'] ?? null);
                $currency['rounding'] = (float) ($currency['rounding'] ?? 0.00);
                $currency['active'] = (bool) ($currency['active'] ?? true);
                $currency['created_at'] = now();
                $currency['updated_at'] = now();

                if ($hasCodeColumn) {
                    $currency['code'] = mb_strtoupper((string) $currency['name']);
                }

                return $currency;
            })->toArray();

            DB::table('currencies')->upsert(
                $currencies,
                ['id'],
                array_values(array_diff(array_keys($currencies[0] ?? []), ['id'])),
            );
        }
    }
}
