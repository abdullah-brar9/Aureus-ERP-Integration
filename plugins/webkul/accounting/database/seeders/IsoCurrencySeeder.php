<?php

namespace Webkul\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Accounting\Services\Currency\IsoCurrencySynchronizer;

class IsoCurrencySeeder extends Seeder
{
    public function run(): void
    {
        app(IsoCurrencySynchronizer::class)->synchronize();
    }
}
