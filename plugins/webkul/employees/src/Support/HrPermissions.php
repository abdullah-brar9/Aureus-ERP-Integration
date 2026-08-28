<?php

namespace Webkul\Employee\Support;

final class HrPermissions
{
    public const ViewAllRecords = 'hr_view_all_records';

    public const ViewSensitiveEmployeeData = 'hr_view_sensitive_employee_data';

    public const ManageSensitiveEmployeeData = 'hr_manage_sensitive_employee_data';

    public const ManageTeams = 'hr_manage_teams';

    public const ManageAttendance = 'hr_manage_attendance';

    public const ApproveTimesheets = 'hr_approve_timesheets';

    public const ApproveLeave = 'hr_approve_leave';

    public const ManagePerformance = 'hr_manage_performance';

    public const ManageEmployeeRequests = 'hr_manage_employee_requests';

    public const ProcessFinancialRequests = 'hr_process_financial_requests';

    public const ConvertCandidates = 'hr_convert_candidates';

    public const ViewAnalytics = 'page_employees_hr_analytics';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::ViewAllRecords,
            self::ViewSensitiveEmployeeData,
            self::ManageSensitiveEmployeeData,
            self::ManageTeams,
            self::ManageAttendance,
            self::ApproveTimesheets,
            self::ApproveLeave,
            self::ManagePerformance,
            self::ManageEmployeeRequests,
            self::ProcessFinancialRequests,
            self::ConvertCandidates,
            self::ViewAnalytics,
        ];
    }

    /** @return array<int, string> */
    public static function manager(): array
    {
        return [
            self::ManageAttendance,
            self::ApproveTimesheets,
            self::ApproveLeave,
            self::ManagePerformance,
            self::ManageEmployeeRequests,
            self::ViewAnalytics,
        ];
    }
}
