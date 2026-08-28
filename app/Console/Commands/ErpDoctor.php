<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Read-only health check for an Aureus ERP instance.
 *
 * Diagnoses the common causes of "cannot log in / 403 / blank panel": database
 * connectivity, missing foundation data (company, currency), an admin user
 * without an active flag / role / permissions / company, uninstalled plugins,
 * and missing core tables. It changes NOTHING — run `erp:repair-access` to fix
 * what it reports.
 */
class ErpDoctor extends Command
{
    protected $signature = 'erp:doctor {--email= : Focus the user checks on this email}';

    protected $description = 'Diagnose ERP login/access/runtime problems (read-only).';

    protected int $failures = 0;

    protected int $warnings = 0;

    public function handle(): int
    {
        $this->info('Aureus ERP Doctor — read-only diagnostics');
        $this->line('Database: '.config('database.connections.'.config('database.default').'.database'));
        $this->newLine();

        $this->checkDatabase();
        $this->checkFoundation();
        $this->checkPlugins();
        $this->checkCoreTables();
        $this->checkAdminAccess();

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("Doctor found {$this->failures} problem(s) and {$this->warnings} warning(s).");
            $this->line('Run `php artisan erp:repair-access` to repair access/foundation issues.');

            return self::FAILURE;
        }

        $this->info($this->warnings > 0
            ? "Healthy, with {$this->warnings} warning(s)."
            : 'All checks passed — the ERP looks healthy.');

        return self::SUCCESS;
    }

    protected function pass(string $msg): void
    {
        $this->line('  <fg=green>OK</>  '.$msg);
    }

    protected function flag(string $msg): void
    {
        $this->warnings++;
        $this->line('  <fg=yellow>!</>   '.$msg);
    }

    protected function bad(string $msg): void
    {
        $this->failures++;
        $this->line('  <fg=red>X</>   '.$msg);
    }

    protected function checkDatabase(): void
    {
        $this->line('<options=bold>Database</>');

        try {
            DB::connection()->getPdo();
            $this->pass('Database connection OK.');
        } catch (\Throwable $e) {
            $this->bad('Cannot connect to the database: '.$e->getMessage());
        }
    }

    protected function checkFoundation(): void
    {
        $this->line('<options=bold>Foundation data</>');

        $companies = Company::query()->count();
        $companies > 0
            ? $this->pass("Companies present ({$companies}).")
            : $this->bad('No company exists — the panel and accounting need at least one.');

        if (Schema::hasTable('currencies')) {
            $currencies = DB::table('currencies')->count();
            $currencies > 0
                ? $this->pass("Currencies seeded ({$currencies}).")
                : $this->bad('No currencies — company/accounting setup will fail.');
        }
    }

    protected function checkPlugins(): void
    {
        $this->line('<options=bold>Plugins</>');

        if (! Schema::hasTable('plugins')) {
            $this->flag('No plugins table — plugin manager not migrated.');

            return;
        }

        foreach (['products', 'accounts', 'accounting'] as $name) {
            $plugin = DB::table('plugins')->where('name', $name)->first();

            if (! $plugin) {
                $this->flag("Plugin '{$name}' is not registered.");
            } elseif (! $plugin->is_installed) {
                $this->bad("Plugin '{$name}' is registered but NOT installed.");
            } else {
                $this->pass("Plugin '{$name}' installed.");
            }
        }
    }

    protected function checkCoreTables(): void
    {
        $this->line('<options=bold>Core accounting tables</>');

        foreach ([
            'accounts_accounts',
            'accounts_account_moves',
            'accounts_account_move_lines',
            'accounts_journals',
        ] as $table) {
            Schema::hasTable($table)
                ? $this->pass("Table {$table} present.")
                : $this->bad("Table {$table} MISSING — install the accounts plugin.");
        }
    }

    protected function checkAdminAccess(): void
    {
        $this->line('<options=bold>Admin access</>');

        $email = $this->option('email');

        $users = User::query()->count();

        if ($users === 0) {
            $this->bad('No users exist — nobody can log in.');

            return;
        }

        $user = $email
            ? User::query()->where('email', $email)->first()
            : User::query()->orderBy('id')->first();

        if (! $user) {
            $this->bad($email ? "No user with email {$email}." : 'No user found.');

            return;
        }

        $this->line("  (checking user #{$user->id} {$user->email})");

        $user->is_active
            ? $this->pass('User is active (can access the panel).')
            : $this->bad('User is NOT active — canAccessPanel() will deny login.');

        $user->email_verified_at
            ? $this->pass('Email verified.')
            : $this->flag('Email not verified (only matters if email verification is enforced).');

        $user->default_company_id
            ? $this->pass('Default company set.')
            : $this->bad('No default company — company-scoped screens break.');

        $allowed = DB::table('user_allowed_companies')->where('user_id', $user->id)->count();
        $allowed > 0
            ? $this->pass("Allowed companies set ({$allowed}).")
            : $this->flag('No allowed companies — multi-company switching unavailable.');

        $roles = $user->getRoleNames();
        $roles->isNotEmpty()
            ? $this->pass('Roles: '.$roles->implode(', ').'.')
            : $this->bad('User has NO role — every permission-gated screen 403s.');

        $totalPerms = Permission::query()->count();
        $effective = $user->getAllPermissions()->count();

        if ($totalPerms === 0) {
            $this->flag('No permissions exist — run shield:generate (plugin install does this).');
        } elseif ($effective === 0) {
            $this->bad("User has 0 of {$totalPerms} permissions — panel resources hidden.");
        } elseif ($effective < $totalPerms) {
            $this->flag("User has {$effective}/{$totalPerms} permissions (not full admin).");
        } else {
            $this->pass("User has all {$totalPerms} permissions.");
        }

        $adminRole = Role::query()->where('name', 'Admin')->first();
        $adminRole
            ? $this->pass('Admin role exists.')
            : $this->flag('No Admin role.');
    }
}
