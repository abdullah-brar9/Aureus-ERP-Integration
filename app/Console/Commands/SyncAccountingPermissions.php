<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;

class SyncAccountingPermissions extends Command
{
    protected $signature = 'accounting:sync-permissions';

    protected $description = 'Register accounting workflow permissions and grant them to existing accounting roles';

    public function handle(AccountingPermissionRegistrar $registrar): int
    {
        $result = $registrar->synchronize();

        $this->info("Accounting permissions synchronized: {$result['permissions']}.");
        $this->line("Roles updated — admin: {$result['admin_roles']}, manager: {$result['manager_roles']}, accountant: {$result['accountant_roles']}.");

        return self::SUCCESS;
    }
}
