<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Database\Factories\ReportLineInputFactory;
use Webkul\Accounting\Models\Concerns\InteractsWithReportTemplate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One manually entered value for a manual (value_source = manual) report line.
 *
 * Entries are point-in-time: a report period's value is the sum of the entries
 * whose date falls inside the period, so a monthly series aggregates naturally
 * into quarterly / yearly columns. Non-additive series (e.g. FX rates) should
 * use an external provider or a consolidation/total formula instead of relying
 * on the summed total column.
 */
class ReportLineInput extends Model
{
    use HasFactory, InteractsWithReportTemplate;

    /**
     * Manual values are operational data (monthly KPI/rate entry), not report
     * structure — they stay editable after the template is published.
     */
    protected bool $guardedByTemplateStatus = false;

    protected $table = 'accounting_report_line_inputs';

    protected $fillable = [
        'report_line_id',
        'company_id',
        'creator_id',
        'date',
        'value',
    ];

    protected $casts = [
        'date'  => 'date',
        'value' => 'decimal:6',
    ];

    /**
     * Touch the owning line (which in turn touches its template) so cached
     * report results are invalidated when a manual value changes.
     *
     * @var array<int, string>
     */
    protected $touches = ['line'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'report_line_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owningTemplate(): ?ReportTemplate
    {
        return $this->line?->template;
    }

    public function getModelTitle(): string
    {
        return 'Manual value on "'.($this->line?->caption ?? "line #{$this->report_line_id}").'"';
    }

    /**
     * @return array<int|string, string>
     */
    public function getLogAttributeLabels(): array
    {
        return [
            'date'         => 'Date',
            'value'        => 'Value',
            'company.name' => 'Company',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($input) {
            $input->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory()
    {
        return ReportLineInputFactory::new();
    }
}
