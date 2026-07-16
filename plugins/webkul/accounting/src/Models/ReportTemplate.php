<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Accounting\Database\Factories\ReportTemplateFactory;
use Webkul\Accounting\Enums\CurrencyMode;
use Webkul\Accounting\Enums\EntityMode;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ReportTemplate extends Model implements Sortable
{
    use HasFactory, SoftDeletes, SortableTrait;

    protected $table = 'accounting_report_templates';

    protected $fillable = [
        'company_id',
        'creator_id',
        'parent_template_id',
        'sort',
        'name',
        'code',
        'layout_type',
        'currency_mode',
        'entity_mode',
        'status',
        'version',
        'description',
    ];

    protected $casts = [
        'layout_type'   => LayoutType::class,
        'currency_mode' => CurrencyMode::class,
        'entity_mode'   => EntityMode::class,
        'status'        => TemplateStatus::class,
        'version'       => 'integer',
        'sort'          => 'integer',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_template_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_template_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReportLine::class, 'report_template_id')->orderBy('sort');
    }

    public function rootLines(): HasMany
    {
        return $this->lines()->whereNull('parent_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($template) {
            $template->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory()
    {
        return ReportTemplateFactory::new();
    }
}
