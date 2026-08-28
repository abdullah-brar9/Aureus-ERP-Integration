<?php

namespace Webkul\Analytic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;

class Record extends Model
{
    protected $table = 'analytic_records';

    protected $fillable = [
        'type',
        'name',
        'date',
        'amount',
        'unit_amount',
        'is_billable',
        'overtime_hours',
        'workflow_status',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'approved_by',
        'approval_request_id',
        'partner_id',
        'company_id',
        'user_id',
        'creator_id',
        'project_id',
        'task_id',
    ];

    protected $casts = [
        'date'           => 'date',
        'is_billable'    => 'boolean',
        'overtime_hours' => 'decimal:4',
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($record) {
            $record->creator_id ??= Auth::id();
        });
    }
}
