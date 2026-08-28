<?php

namespace Webkul\Employee\Services;

use Illuminate\Support\Facades\DB;

class HrAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(int $companyId, string $from, string $to): array
    {
        $headcount = DB::table('employees_employees')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->count();
        $departures = DB::table('employees_employees')
            ->where('company_id', $companyId)
            ->whereBetween('leaving_date', [$from, $to])
            ->count();
        $attendance = DB::table('employees_attendance_records')
            ->where('company_id', $companyId)
            ->whereBetween('attendance_date', [$from, $to]);
        $timesheets = DB::table('analytic_records')
            ->where('company_id', $companyId)
            ->where('type', 'projects')
            ->whereBetween('date', [$from, $to]);
        $totalHours = (float) (clone $timesheets)->sum('unit_amount');
        $billableHours = (float) (clone $timesheets)->where('is_billable', true)->sum('unit_amount');
        $timesheetUsers = (clone $timesheets)->distinct()->count('user_id');

        return [
            'headcount'                 => $headcount,
            'department_distribution'   => DB::table('employees_employees as employees')
                ->leftJoin('employees_departments as departments', 'departments.id', '=', 'employees.department_id')
                ->where('employees.company_id', $companyId)
                ->whereNull('employees.deleted_at')
                ->where('employees.is_active', true)
                ->groupBy('departments.id', 'departments.name')
                ->selectRaw("COALESCE(departments.name, 'Unassigned') name, COUNT(*) total")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => ['name' => $row->name, 'total' => (int) $row->total])
                ->all(),
            'departures'                => $departures,
            'turnover_rate'             => $headcount + $departures > 0 ? round(($departures / ($headcount + $departures)) * 100, 2) : 0.0,
            'attendance_records'        => (clone $attendance)->count(),
            'late_arrivals'             => (clone $attendance)->where('late_minutes', '>', 0)->count(),
            'early_departures'          => (clone $attendance)->where('early_departure_minutes', '>', 0)->count(),
            'attendance_overtime_hours' => round((float) (clone $attendance)->sum('overtime_hours'), 2),
            'leave_days'                => round((float) DB::table('time_off_leaves')
                ->where('company_id', $companyId)
                ->where('state', 'validate_two')
                ->whereDate('date_from', '<=', $to)
                ->whereDate('date_to', '>=', $from)
                ->sum('number_of_days'), 2),
            'timesheet_hours'           => round($totalHours, 2),
            'billable_hours'            => round($billableHours, 2),
            'utilization_rate'          => $totalHours > 0 ? round(($billableHours / $totalHours) * 100, 2) : 0.0,
            'timesheet_compliance_rate' => $headcount > 0 ? round(($timesheetUsers / $headcount) * 100, 2) : 0.0,
            'timesheet_overtime_hours'  => round((float) (clone $timesheets)->sum('overtime_hours'), 2),
            'monthly_payroll_cost'      => round((float) DB::table('employees_employees')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->sum('base_salary'), 2),
            'average_performance'       => round((float) DB::table('employees_performance_reviews')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereNotNull('manager_rating')
                ->avg('manager_rating'), 2),
            'recruitment_funnel'        => DB::table('recruitments_applicants as applications')
                ->leftJoin('recruitments_stages as stages', 'stages.id', '=', 'applications.stage_id')
                ->where('applications.company_id', $companyId)
                ->whereBetween('applications.create_date', [$from, $to])
                ->groupBy('stages.id', 'stages.name')
                ->selectRaw("COALESCE(stages.name, 'Unassigned') stage, COUNT(*) total")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => ['stage' => $row->stage, 'total' => (int) $row->total])
                ->all(),
            'average_time_to_hire_days' => round((float) DB::table('recruitments_applicants')
                ->where('company_id', $companyId)
                ->whereNotNull('date_closed')
                ->whereBetween('date_closed', [$from, $to])
                ->selectRaw('AVG(DATEDIFF(date_closed, create_date)) average_days')
                ->value('average_days'), 2),
            'applicant_sources'         => DB::table('recruitments_applicants as applications')
                ->leftJoin('utm_sources as sources', 'sources.id', '=', 'applications.source_id')
                ->where('applications.company_id', $companyId)
                ->whereBetween('applications.create_date', [$from, $to])
                ->groupBy('sources.id', 'sources.name')
                ->selectRaw("COALESCE(sources.name, 'Unspecified') source, COUNT(*) total, SUM(CASE WHEN applications.date_closed IS NOT NULL THEN 1 ELSE 0 END) hired")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => ['source' => $row->source, 'total' => (int) $row->total, 'hired' => (int) $row->hired])
                ->all(),
            'open_requests'             => DB::table('employees_requests')
                ->where('company_id', $companyId)
                ->whereIn('status', ['draft', 'pending_approval'])
                ->count(),
            'approved_financial_requests'=> round((float) DB::table('employees_requests as requests')
                ->join('employees_request_types as types', 'types.id', '=', 'requests.request_type_id')
                ->where('requests.company_id', $companyId)
                ->where('types.is_financial', true)
                ->where('requests.status', 'approved')
                ->whereBetween('requests.approved_at', [$from, $to])
                ->sum('requests.amount'), 2),
        ];
    }
}
