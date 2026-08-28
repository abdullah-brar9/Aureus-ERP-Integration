<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Move;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class EmployeeRequest extends Model
{
    protected $table = 'employees_requests';

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'request_type_id', 'requested_by', 'currency_id',
        'approval_request_id', 'accounting_move_id', 'reference', 'title', 'description',
        'amount', 'payload', 'attachments', 'status', 'rejection_reason', 'submitted_at',
        'approved_at', 'rejected_at', 'posted_to_accounting_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'                  => 'decimal:4',
            'payload'                 => 'array',
            'attachments'             => 'array',
            'submitted_at'            => 'datetime',
            'approved_at'             => 'datetime',
            'rejected_at'             => 'datetime',
            'posted_to_accounting_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequestType::class, 'request_type_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function accountingMove(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }

    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        if ((int) $this->approval_request_id === (int) $request->id) {
            app(EmployeeRequestService::class)->synchronize($this);
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->requested_by ??= Auth::id();
            $request->company_id ??= $request->employee?->company_id;
            $request->currency_id ??= $request->company?->currency_id;
        });
    }
}
