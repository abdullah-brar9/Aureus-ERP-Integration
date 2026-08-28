<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class EmployeeRequestType extends Model
{
    protected $table = 'employees_request_types';

    protected $fillable = [
        'company_id', 'journal_id', 'debit_account_id', 'credit_account_id', 'creator_id',
        'code', 'name', 'category', 'approval_request_type', 'is_financial', 'requires_amount',
        'requires_document', 'is_active', 'configuration',
    ];

    protected function casts(): array
    {
        return [
            'is_financial'      => 'boolean',
            'requires_amount'   => 'boolean',
            'requires_document' => 'boolean',
            'is_active'         => 'boolean',
            'configuration'     => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class, 'request_type_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $type): void {
            $type->creator_id ??= Auth::id();
        });
    }
}
