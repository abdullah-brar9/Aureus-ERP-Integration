<?php

namespace Webkul\Employee\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;

class HrHierarchyService
{
    /** @return Collection<int, int> */
    public function visibleEmployeeIds(User $user, int $companyId): Collection
    {
        $this->assertCompanyAccess($user, $companyId);

        if ($user->can('hr_view_all_records')) {
            return Employee::query()->where('company_id', $companyId)->pluck('id');
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();
        if (! $employee) {
            return collect();
        }

        $visible = collect([$employee->id]);
        $frontier = collect([$employee->id]);
        while ($frontier->isNotEmpty()) {
            $children = Employee::query()
                ->where('company_id', $companyId)
                ->whereIn('parent_id', $frontier)
                ->pluck('id');
            $frontier = $children->diff($visible)->values();
            $visible = $visible->merge($frontier);
        }

        $managedDepartments = Department::query()
            ->where('company_id', $companyId)
            ->where('manager_id', $employee->id)
            ->pluck('id');
        if ($managedDepartments->isNotEmpty()) {
            $departmentIds = Department::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $query) use ($managedDepartments): void {
                    $query->whereIn('id', $managedDepartments);
                    foreach ($managedDepartments as $departmentId) {
                        $query->orWhere('parent_path', 'like', '%/'.$departmentId.'/%');
                    }
                })
                ->pluck('id');
            $visible = $visible->merge(
                Employee::query()->where('company_id', $companyId)->whereIn('department_id', $departmentIds)->pluck('id'),
            );
        }

        $managedTeamIds = Team::query()
            ->where('company_id', $companyId)
            ->where('manager_employee_id', $employee->id)
            ->pluck('id');
        if ($managedTeamIds->isNotEmpty()) {
            $visible = $visible->merge(
                Employee::query()->where('company_id', $companyId)->whereIn('team_id', $managedTeamIds)->pluck('id'),
            );
        }

        return $visible->map(fn ($id): int => (int) $id)->unique()->values();
    }

    public function scopeVisible(Builder $query, User $user, int $companyId): Builder
    {
        return $query
            ->where('company_id', $companyId)
            ->whereIn('id', $this->visibleEmployeeIds($user, $companyId));
    }

    public function assertCanManage(User $user, Employee $employee): void
    {
        if (! $this->visibleEmployeeIds($user, (int) $employee->company_id)->contains((int) $employee->id)) {
            throw new RuntimeException('The employee is outside this user’s HR hierarchy scope.');
        }
    }

    private function assertCompanyAccess(User $user, int $companyId): void
    {
        if ((int) $user->default_company_id !== $companyId && ! $user->allowedCompanies()->whereKey($companyId)->exists()) {
            throw new RuntimeException('The user does not have access to this company.');
        }
    }
}
