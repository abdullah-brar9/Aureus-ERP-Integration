<?php

namespace Webkul\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;

class AccountingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(AccountingPermissionRegistrar::class)->synchronize();
    }
}
