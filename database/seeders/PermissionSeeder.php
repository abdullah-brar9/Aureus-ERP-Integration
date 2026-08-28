<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Employee\Services\Security\HrPermissionRegistrar;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $exitCode = Artisan::call('shield:generate', [
            '--all'    => true,
            '--option' => 'permissions',
            '--panel'  => 'admin',
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to generate admin panel permissions: '.Artisan::output());
        }

        app(AccountingPermissionRegistrar::class)->synchronize();
        app(HrPermissionRegistrar::class)->synchronize();

        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'web'],
            ['is_default' => true],
        );

        $adminRole->syncPermissions(
            Permission::query()->where('guard_name', 'web')->get(),
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
