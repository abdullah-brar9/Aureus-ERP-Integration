<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ConfigurationAudit extends Model
{
    public $timestamps = false;

    protected $table = 'accounting_configuration_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before'     => 'array',
            'after'      => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
