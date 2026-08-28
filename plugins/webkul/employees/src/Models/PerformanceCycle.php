<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class PerformanceCycle extends Model
{
    protected $table = 'employees_performance_cycles';

    protected $fillable = ['company_id', 'creator_id', 'name', 'starts_on', 'ends_on', 'status', 'competency_framework', 'settings'];

    protected function casts(): array
    {
        return [
            'starts_on'            => 'date',
            'ends_on'              => 'date',
            'competency_framework' => 'array',
            'settings'             => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'cycle_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $cycle): void {
            $cycle->creator_id ??= Auth::id();
        });
    }
}
