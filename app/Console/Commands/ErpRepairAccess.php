<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Idempotently repairs admin access to the ERP. Safe to run repeatedly.
 *
 * It NEVER deletes users, companies, plugins, journals or reports. It only
 * ensures an active admin user exists with the Admin role, that the Admin role
 * holds all permissions, and that the user has a default + allowed company.
 * When no user exists at all it creates one (email/password overridable).
 */
class ErpRepairAccess extends Command
{
    protected $signature = 'erp:repair-access
        {--email=admin@example.com : Admin email to ensure/repair}
        {--password= : Set/reset this password (only if provided)}
        {--name=Administrator : Name to use when creating a new user}';

    protected $description = 'Idempotently repair admin login/role/company access (non-destructive).';

    public function handle(): int
    {
        $email = (string) $this->option('email');

        $company = Company::query()->orderBy('id')->first();

        if (! $company) {
            $this->error('No company exists. Seed the foundation first (a company is required); this command does not create companies.');

            return self::FAILURE;
        }
        $this->line("Using company #{$company->id} \"{$company->name}\".");

        $adminRole = $this->ensureAdminRoleWithAllPermissions();

        $user = $this->ensureAdminUser($email, $company);

        // Assign the Admin role (idempotent).
        if (! $user->hasRole($adminRole)) {
            $user->assignRole($adminRole);
            $this->line("Assigned '{$adminRole->name}' role to {$user->email}.");
        } else {
            $this->line("User already has '{$adminRole->name}' role.");
        }

        // Ensure the user can reach and use the panel.
        $user->forceFill([
            'is_active'           => true,
            'email_verified_at'   => $user->email_verified_at ?? now(),
            'default_company_id'  => $user->default_company_id ?: $company->id,
            'resource_permission' => PermissionType::GLOBAL->value,
        ])->save();

        // Ensure the default company is among the allowed companies (idempotent).
        $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info("Access repaired for {$user->email} (user #{$user->id}).");
        $this->line('Run `php artisan erp:doctor` to confirm, then log in at /admin.');

        if ($this->option('password')) {
            $this->line('Password was set from the --password option.');
        } elseif ($user->wasRecentlyCreated) {
            $this->warn('A new user was created with the default password "password" — change it after logging in.');
        }

        return self::SUCCESS;
    }

    protected function ensureAdminRoleWithAllPermissions(): Role
    {
        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'web'],
        );

        $allPermissions = Permission::query()->where('guard_name', 'web')->get();

        if ($allPermissions->isNotEmpty()) {
            $role->syncPermissions($allPermissions);
            $this->line("Admin role granted all {$allPermissions->count()} permissions.");
        } else {
            $this->warn('No permissions found to grant (run the plugin install / shield:generate).');
        }

        return $role;
    }

    protected function ensureAdminUser(string $email, Company $company): User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first()
            ?? User::query()->orderBy('id')->first();

        if ($user) {
            $this->line("Repairing existing user #{$user->id} ({$user->email}).");

            if ($this->option('password')) {
                $user->forceFill(['password' => Hash::make((string) $this->option('password'))])->save();
            }

            return $user;
        }

        // No user at all — create one. Reuse the company's partner so the
        // user has a valid partner link, mirroring a normal install.
        $user = new User;
        $user->forceFill([
            'name'               => (string) $this->option('name'),
            'email'              => $email,
            'password'           => Hash::make((string) ($this->option('password') ?: 'password')),
            'partner_id'         => $company->partner_id,
            'is_active'          => true,
            'email_verified_at'  => now(),
            'default_company_id' => $company->id,
        ])->save();

        $this->line("Created admin user {$email} (#{$user->id}).");

        return $user;
    }
}
