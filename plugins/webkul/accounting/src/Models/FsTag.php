<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\Concerns\AuditsConfiguration;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class FsTag extends Model
{
    use AuditsConfiguration;

    protected $table = 'accounting_fs_tags';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $tag): void {
            $tag->normalized_name = Str::of($tag->name)->squish()->lower()->value();
            $tag->code = Str::upper(trim($tag->code));
        });
    }
}
