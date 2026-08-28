<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class EmployeeStatusHistory extends Model
{
    protected $table = 'employees_employee_status_histories';

    protected $fillable = [
        'company_id',
        'employee_id',
        'changed_by',
        'status',
        'effective_date',
        'reason',
        'previous_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'effective_date'  => 'date',
            'previous_values' => 'array',
            'new_values'      => 'array',
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
