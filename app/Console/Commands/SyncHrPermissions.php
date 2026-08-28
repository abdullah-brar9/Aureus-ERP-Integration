<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Security\HrPermissionRegistrar;

class SyncHrPermissions extends Command
{
    protected $signature = 'hr:sync-permissions';

    protected $description = 'Register HR workflow permissions and grant them to existing HR roles';

    public function handle(HrPermissionRegistrar $registrar): int
    {
        $result = $registrar->synchronize();
        $this->info("HR permissions synchronized: {$result['permissions']}.");
        $this->line("Roles updated — admin: {$result['admin_roles']}, HR: {$result['hr_roles']}, managers: {$result['manager_roles']}.");

        return self::SUCCESS;
    }
}
