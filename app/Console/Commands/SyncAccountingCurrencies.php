<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Services\Currency\IsoCurrencySynchronizer;

class SyncAccountingCurrencies extends Command
{
    protected $signature = 'accounting:sync-currencies';

    protected $description = 'Idempotently synchronize the current ISO 4217 fiat currency master';

    public function handle(IsoCurrencySynchronizer $synchronizer): int
    {
        $result = $synchronizer->synchronize();

        $this->info("ISO fiat currencies synchronized: {$result['activated']} active of {$result['total']}; {$result['created']} added.");
        $this->line("Non-current ISO records inactive: {$result['deactivated']}.");

        return self::SUCCESS;
    }
}
