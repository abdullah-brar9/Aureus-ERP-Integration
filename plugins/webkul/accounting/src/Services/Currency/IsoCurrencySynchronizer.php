<?php

namespace Webkul\Accounting\Services\Currency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class IsoCurrencySynchronizer
{
    private const CURRENT_FIAT_CODES = 'AED,AFN,ALL,AMD,AOA,ARS,AUD,AWG,AZN,BAM,BBD,BDT,BHD,BIF,BMD,BND,BOB,BRL,BSD,BTN,BWP,BYN,BZD,CAD,CDF,CHF,CLP,CNY,COP,CRC,CUP,CVE,CZK,DJF,DKK,DOP,DZD,EGP,ERN,ETB,EUR,FJD,FKP,GBP,GEL,GHS,GIP,GMD,GNF,GTQ,GYD,HKD,HNL,HTG,HUF,IDR,ILS,INR,IQD,IRR,ISK,JMD,JOD,JPY,KES,KGS,KHR,KMF,KPW,KRW,KWD,KYD,KZT,LAK,LBP,LKR,LRD,LSL,LYD,MAD,MDL,MGA,MKD,MMK,MNT,MOP,MRU,MUR,MVR,MWK,MXN,MYR,MZN,NAD,NGN,NIO,NOK,NPR,NZD,OMR,PAB,PEN,PGK,PHP,PKR,PLN,PYG,QAR,RON,RSD,RUB,RWF,SAR,SBD,SCR,SDG,SEK,SGD,SHP,SLE,SOS,SRD,SSP,STN,SVC,SYP,SZL,THB,TJS,TMT,TND,TOP,TRY,TTD,TWD,TZS,UAH,UGX,USD,UYU,UZS,VED,VES,VND,VUV,WST,XAF,XCD,XCG,XOF,XPF,YER,ZAR,ZMW,ZWG';

    /**
     * ISO 4217 List One published by SIX on 2026-01-01, excluding funds,
     * metals, testing codes, and accounting units.
     *
     * @return array{created: int, activated: int, deactivated: int, total: int}
     */
    public function synchronize(): array
    {
        $sourcePath = base_path('plugins/webkul/security/src/Data/currencies.json');
        if (! File::exists($sourcePath)) {
            throw new RuntimeException('The bundled currency metadata file is missing.');
        }

        $metadata = collect(json_decode(File::get($sourcePath), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy(fn (array $currency): string => mb_strtoupper((string) $currency['name']));

        $metadata = $metadata->merge($this->supplementalMetadata());
        $codes = $this->currentCodes();
        $before = DB::table('currencies')->whereIn('code', $codes)->count();
        $now = now();

        $rows = collect($codes)->map(function (string $code, int $index) use ($metadata, $now): array {
            $currency = $metadata->get($code);
            if (! is_array($currency)) {
                throw new RuntimeException("ISO currency metadata is missing for {$code}.");
            }

            $minorUnits = (int) $currency['decimal_places'];

            return [
                'code'           => $code,
                'name'           => $code,
                'symbol'         => $currency['symbol'] ?? $code,
                'iso_numeric'    => (int) $currency['iso_numeric'],
                'decimal_places' => $minorUnits,
                'full_name'      => $currency['full_name'],
                'rounding'       => $currency['rounding'] ?? $this->roundingIncrement($minorUnits),
                'active'         => true,
                'is_iso_fiat'    => true,
                'display_order'  => ($index + 1) * 10,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        })->all();

        DB::transaction(function () use ($rows, $codes, $now): void {
            DB::table('currencies')->insertOrIgnore($rows);
            DB::table('currencies')->upsert(
                $rows,
                ['code'],
                ['active', 'is_iso_fiat', 'display_order', 'updated_at'],
            );

            DB::table('currencies')
                ->whereNotNull('code')
                ->whereNotIn('code', $codes)
                ->update([
                    'active'      => false,
                    'is_iso_fiat' => false,
                    'updated_at'  => $now,
                ]);

            DB::table('currencies')->where('code', 'VES')->update([
                'iso_numeric'    => 928,
                'decimal_places' => 2,
                'updated_at'     => $now,
            ]);
        });

        $after = DB::table('currencies')->whereIn('code', $codes)->count();

        return [
            'created'     => $after - $before,
            'activated'   => DB::table('currencies')->whereIn('code', $codes)->where('active', true)->count(),
            'deactivated' => DB::table('currencies')->whereNotNull('code')->whereNotIn('code', $codes)->where('active', false)->count(),
            'total'       => count($codes),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function currentCodes(): array
    {
        return explode(',', self::CURRENT_FIAT_CODES);
    }

    /**
     * @return array<string, array<string, int|string|bool>>
     */
    private function supplementalMetadata(): array
    {
        return [
            'VED' => [
                'name'      => 'VED', 'symbol' => 'Bs.D', 'iso_numeric' => 926, 'decimal_places' => 2,
                'full_name' => 'Bolivar Soberano', 'rounding' => '0.01', 'active' => true,
            ],
            'XCG' => [
                'name'      => 'XCG', 'symbol' => 'Cg', 'iso_numeric' => 532, 'decimal_places' => 2,
                'full_name' => 'Caribbean Guilder', 'rounding' => '0.01', 'active' => true,
            ],
            'ZWG' => [
                'name'      => 'ZWG', 'symbol' => 'ZiG', 'iso_numeric' => 924, 'decimal_places' => 2,
                'full_name' => 'Zimbabwe Gold', 'rounding' => '0.01', 'active' => true,
            ],
        ];
    }

    private function roundingIncrement(int $minorUnits): string
    {
        if ($minorUnits <= 0) {
            return '1';
        }

        return '0.'.str_repeat('0', $minorUnits - 1).'1';
    }
}
