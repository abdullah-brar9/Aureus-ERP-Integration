<?php

namespace Webkul\Accounting\Services\Security;

use Illuminate\Support\Facades\DB;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\PermissionRegistrar;

class AccountingPermissionRegistrar
{
    /**
     * @return array{permissions: int, admin_roles: int, manager_roles: int, accountant_roles: int}
     */
    public function synchronize(): array
    {
        $now = now();
        $names = collect(AccountingPermissions::all())->unique()->values();

        Permission::query()->insertOrIgnore($names->map(fn (string $name): array => [
            'name'       => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $permissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('id', 'name');

        $adminRoles = $this->rolesNamed(Role::getSystemRoleNames());
        $managerRoles = $this->rolesNamed(['accounting_manager', 'accounting manager', 'finance_manager', 'finance manager']);
        $accountantRoles = $this->rolesNamed(['accountant', 'accounting', 'finance_user', 'finance user']);

        $this->grant($adminRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant($managerRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant(
            $accountantRoles->pluck('id')->all(),
            $permissionIds->only(AccountingPermissions::accountant())->values()->all(),
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'permissions'      => $permissionIds->count(),
            'admin_roles'      => $adminRoles->count(),
            'manager_roles'    => $managerRoles->count(),
            'accountant_roles' => $accountantRoles->count(),
        ];
    }

    private function rolesNamed(array $names)
    {
        $normalized = collect($names)->map(fn (string $name): string => mb_strtolower(trim($name)))->unique();

        return Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $normalized->contains(mb_strtolower((string) $role->getRawOriginal('name'))));
    }

    private function grant(array $roleIds, array $permissionIds): void
    {
        if ($roleIds === [] || $permissionIds === []) {
            return;
        }

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
            }
        }

        DB::table(config('permission.table_names.role_has_permissions'))->insertOrIgnore($rows);
    }
}
