<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
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
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\Concerns\InteractsWithReportTemplate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ReportLine extends Model implements Sortable
{
    use HasFactory, InteractsWithReportTemplate, SortableTrait;

    protected $table = 'accounting_report_lines';

    protected $fillable = [
        'report_template_id',
        'parent_id',
        'creator_id',
        'company_id',
        'sort',
        'line_type',
        'caption',
        'code',
        'sign',
        'value_source',
        'value_basis',
        'external_provider',
        'is_visible',
        'is_bold',
        'is_check',
        'indent_level',
        'dimension_type',
        'dimension_id',
    ];

    protected $casts = [
        'line_type'    => LineType::class,
        'value_source' => ValueSource::class,
        'value_basis'  => ValueBasis::class,
        'sign'         => 'integer',
        'is_visible'   => 'boolean',
        'is_bold'      => 'boolean',
        'is_check'     => 'boolean',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ReportLineInput::class, 'report_line_id');
    }

    /**
     * How this line's values are produced. A null column derives the source
     * from the line type, which keeps every pre-existing row behaving exactly
     * as before: detail lines read the ledger, subtotal lines evaluate their
     * formulas, headers and spacers carry no values.
     */
    public function effectiveValueSource(): ?ValueSource
    {
        if ($this->value_source !== null) {
            return $this->value_source instanceof ValueSource
                ? $this->value_source
                : ValueSource::from((string) $this->value_source);
        }

        $lineType = $this->line_type instanceof LineType
            ? $this->line_type
            : LineType::from((string) $this->line_type);

        return match ($lineType) {
            LineType::DETAIL   => ValueSource::LEDGER,
            LineType::SUBTOTAL => ValueSource::FORMULA,
            default            => null,
        };
    }

    /**
     * How ledger balances are read for this line, falling back to the given
     * report-level default when the line does not specify a basis.
     */
    public function effectiveValueBasis(ValueBasis $default): ValueBasis
    {
        if ($this->value_basis === null) {
            return $default;
        }

        return $this->value_basis instanceof ValueBasis
            ? $this->value_basis
            : ValueBasis::from((string) $this->value_basis);
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
        return $this->caption ?? "Report line #{$this->id}";
    }

    /**
     * @return array<int|string, string>
     */
    public function getLogAttributeLabels(): array
    {
        return [
            'caption'           => 'Caption',
            'line_type'         => 'Line Type',
            'code'              => 'Code',
            'sign'              => 'Sign',
            'value_source'      => 'Value Source',
            'value_basis'       => 'Value Basis',
            'external_provider' => 'External Provider',
            'is_visible'        => 'Visible',
            'is_bold'           => 'Bold',
            'is_check'          => 'Check Row',
            'indent_level'      => 'Indent',
            'company.name'      => 'Company Override',
        ];
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
