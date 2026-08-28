<?php

namespace Webkul\Employee\Services\Security;

use Illuminate\Support\Facades\DB;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\PermissionRegistrar;

class HrPermissionRegistrar
{
    /** @return array{permissions: int, admin_roles: int, hr_roles: int, manager_roles: int} */
    public function synchronize(): array
    {
        $now = now();
        $names = collect(HrPermissions::all())->unique()->values();
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
        $hrRoles = $this->rolesNamed(['hr', 'hr_manager', 'hr manager', 'human resources', 'human resources manager']);
        $managerRoles = $this->rolesNamed(['manager', 'department_manager', 'department manager', 'team_manager', 'team manager']);
        $this->grant($adminRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant($hrRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant($managerRoles->pluck('id')->all(), $permissionIds->only(HrPermissions::manager())->values()->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'permissions'   => $permissionIds->count(),
            'admin_roles'   => $adminRoles->count(),
            'hr_roles'      => $hrRoles->count(),
            'manager_roles' => $managerRoles->count(),
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
