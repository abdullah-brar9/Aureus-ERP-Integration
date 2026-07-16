<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Database\Factories\ReportLineFactory;
use Webkul\Accounting\Enums\LineType;
use Webkul\Security\Models\User;

class ReportLine extends Model implements Sortable
{
    use HasFactory, SortableTrait;

    protected $table = 'accounting_report_lines';

    protected $fillable = [
        'report_template_id',
        'parent_id',
        'creator_id',
        'sort',
        'line_type',
        'caption',
        'code',
        'sign',
        'is_visible',
        'is_bold',
        'indent_level',
        'dimension_type',
        'dimension_id',
    ];

    protected $casts = [
        'line_type'    => LineType::class,
        'sign'         => 'integer',
        'is_visible'   => 'boolean',
        'is_bold'      => 'boolean',
        'indent_level' => 'integer',
        'sort'         => 'integer',
        'dimension_id' => 'integer',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    /**
     * Touch the parent template on write so cached report results (keyed by the
     * template's updated_at) are invalidated whenever a line changes.
     *
     * @var array<int, string>
     */
    protected $touches = ['template'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function getDescendantIds(): array
    {
        $ids = [];

        foreach ($this->children as $child) {
            $ids = [
                ...$ids,
                $child->id,
                ...$child->getDescendantIds(),
            ];
        }

        return $ids;
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            Account::class,
            'accounting_report_line_accounts',
            'report_line_id',
            'account_id'
        )->withPivot('sign')->withTimestamps();
    }

    public function accountBindings(): HasMany
    {
        return $this->hasMany(ReportLineAccount::class, 'report_line_id');
    }

    public function formulas(): HasMany
    {
        return $this->hasMany(ReportLineFormula::class, 'report_line_id')->orderBy('sort');
    }

    public function formulaOperands(): HasMany
    {
        return $this->hasMany(ReportLineFormula::class, 'operand_line_id');
    }

    public function dimension(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'dimension_type', 'dimension_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($line) {
            $line->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory()
    {
        return ReportLineFactory::new();
    }
}
