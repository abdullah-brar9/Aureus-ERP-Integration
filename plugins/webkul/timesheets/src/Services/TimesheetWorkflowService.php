<?php

namespace Webkul\Timesheet\Services;

use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Timesheet\Models\Timesheet;

class TimesheetWorkflowService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    public function submit(Timesheet $timesheet, User $requester)
    {
        if (! in_array($timesheet->workflow_status, ['draft', 'rejected'], true)) {
            throw new RuntimeException('Only draft or rejected timesheets can be submitted.');
        }
        if ((int) $timesheet->user_id !== (int) $requester->id && ! $requester->can('hr_approve_timesheets')) {
            throw new RuntimeException('A user can only submit their own timesheet.');
        }
        $employee = Employee::query()
            ->where('company_id', $timesheet->company_id)
            ->where('user_id', $timesheet->user_id)
            ->firstOrFail();
        $approval = $this->approvals->submit(
            $timesheet,
            $requester,
            'timesheet_submission',
            null,
            [
                'company_id'    => (int) $timesheet->company_id,
                'employee_id'   => (int) $employee->id,
                'department_id' => $employee->department_id,
                'team_id'       => $employee->team_id,
                'project_id'    => $timesheet->project_id,
                'hours'         => (string) $timesheet->unit_amount,
            ],
        );
        $timesheet->update([
            'approval_request_id' => $approval->id,
            'workflow_status'     => 'submitted',
            'submitted_at'        => now(),
            'rejection_reason'    => null,
        ]);

        return $approval;
    }

    public function approve(Timesheet $timesheet, User $actor, ?string $reason = null): Timesheet
    {
        $approval = $timesheet->approvalRequest ?? throw new RuntimeException('The timesheet has not been submitted.');
        $this->approvals->approve($approval, $actor, $reason);

        return $this->synchronize($timesheet, $actor);
    }

    public function reject(Timesheet $timesheet, User $actor, string $reason): Timesheet
    {
        $approval = $timesheet->approvalRequest ?? throw new RuntimeException('The timesheet has not been submitted.');
        $this->approvals->reject($approval, $actor, $reason);

        return $this->synchronize($timesheet, $actor);
    }

    public function synchronize(Timesheet $timesheet, ?User $actor = null): Timesheet
    {
        $timesheet->load('approvalRequest.decisions');
        $approval = $timesheet->approvalRequest;
        if (! $approval) {
            return $timesheet;
        }
        if ($approval->status === 'approved') {
            $timesheet->update([
                'workflow_status' => 'approved',
                'approved_by'     => $actor?->id ?? $approval->decisions->last()?->actor_id,
                'approved_at'     => $approval->completed_at ?? now(),
            ]);
        } elseif ($approval->status === 'rejected') {
            $timesheet->update([
                'workflow_status'  => 'rejected',
                'rejection_reason' => $approval->decisions->last()?->reason,
                'approved_by'      => null,
                'approved_at'      => null,
            ]);
        }

        return $timesheet->fresh(['approvalRequest']);
    }
}
