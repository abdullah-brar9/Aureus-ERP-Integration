<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Accounting\Database\Factories\ReportColumnFactory;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Models\Concerns\InteractsWithReportTemplate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ReportColumn extends Model implements Sortable
{
    use HasFactory, InteractsWithReportTemplate, SortableTrait;

    protected $table = 'accounting_report_columns';

    protected $fillable = [
        'report_template_id',
        'creator_id',
        'company_id',
        'sort',
        'label',
        'column_type',
        'start_month',
        'end_month',
        'year_offset',
        'is_consolidated',
    ];

    protected $casts = [
        'column_type'     => ColumnType::class,
        'start_month'     => 'integer',
        'end_month'       => 'integer',
        'year_offset'     => 'integer',
        'is_consolidated' => 'boolean',
        'sort'            => 'integer',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    /**
     * Touch the parent template on write so cached report results (keyed by the
     * template's updated_at) are invalidated whenever a column changes.
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Keep sort sequences independent per template.
     */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('report_template_id', $this->report_template_id);
    }

    public function owningTemplate(): ?ReportTemplate
    {
        return $this->template;
    }

    public function getModelTitle(): string
    {
        return $this->label ?? "Report column #{$this->id}";
    }

    /**
     * @return array<int|string, string>
     */
    public function getLogAttributeLabels(): array
    {
        return [
            'label'           => 'Label',
            'column_type'     => 'Column Type',
            'start_month'     => 'Start Month',
            'end_month'       => 'End Month',
            'year_offset'     => 'Year Offset',
            'is_consolidated' => 'Consolidated',
            'company.name'    => 'Company',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($column) {
            $column->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory()
    {
        return ReportColumnFactory::new();
    }
}
