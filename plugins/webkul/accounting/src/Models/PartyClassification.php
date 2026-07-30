<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Webkul\Accounting\Models\Concerns\AuditsConfiguration;
use Webkul\Support\Models\Company;

class PartyClassification extends Model
{
    use AuditsConfiguration;

    protected $table = 'accounting_party_classifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $classification): void {
            $classification->normalized_name = Str::of($classification->name)->squish()->lower()->value();
            $classification->code = Str::upper(trim($classification->code));
        });
    }
}
