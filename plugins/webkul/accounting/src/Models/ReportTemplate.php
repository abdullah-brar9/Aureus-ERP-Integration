<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Accounting\Database\Factories\ReportTemplateFactory;
use Webkul\Accounting\Enums\CurrencyMode;
use Webkul\Accounting\Enums\EntityMode;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Models\Concerns\InteractsWithReportTemplate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ReportTemplate extends Model implements Sortable
{
    use HasFactory, InteractsWithReportTemplate, SoftDeletes, SortableTrait;

    /**
     * The template enforces its own lifecycle rules in boot(); the shared
     * child-structure guard must not apply to the template itself.
     */
    protected bool $guardedByTemplateStatus = false;

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
        'published_at',
        'description',
    ];

    protected $casts = [
        'layout_type'   => LayoutType::class,
        'currency_mode' => CurrencyMode::class,
        'entity_mode'   => EntityMode::class,
        'status'        => TemplateStatus::class,
        'version'       => 'integer',
        'published_at'  => 'datetime',
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

    public function columns(): HasMany
    {
        return $this->hasMany(ReportColumn::class, 'report_template_id')->orderBy('sort');
    }

    public function rootLines(): HasMany
    {
        return $this->lines()->whereNull('parent_id');
    }

    public function statusEnum(): TemplateStatus
    {
        return $this->status instanceof TemplateStatus
            ? $this->status
            : TemplateStatus::from((string) $this->status);
    }

    public function isDraft(): bool
    {
        return $this->statusEnum() === TemplateStatus::DRAFT;
    }

    public function owningTemplate(): ?ReportTemplate
    {
        return $this;
    }

    public function getModelTitle(): string
    {
        return (string) $this->name;
    }

    /**
     * Attributes captured in the audit history (chatter activity log).
     *
     * @return array<int|string, string>
     */
    public function getLogAttributeLabels(): array
    {
        return [
            'name'          => 'Name',
            'code'          => 'Code',
            'layout_type'   => 'Layout',
            'currency_mode' => 'Currency Mode',
            'entity_mode'   => 'Entity Mode',
            'status'        => 'Status',
            'version'       => 'Version',
            'published_at'  => 'Published At',
            'description'   => 'Description',
            'company.name'  => 'Company',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($template) {
            $template->creator_id ??= Auth::id();
        });

        // Published (and archived) versions are immutable: only lifecycle
        // fields and touch-driven timestamp bumps may change. Structural edits
        // require a new draft version (ReportTemplateVersioningService).
        static::updating(function (self $template) {
            $originalStatus = TemplateStatus::from((string) $template->getRawOriginal('status'));

            if ($originalStatus === TemplateStatus::DRAFT) {
                return;
            }

            $allowed = ['status', 'published_at', 'sort', 'updated_at', 'created_at'];

            $blocked = array_diff(array_keys($template->getDirty()), $allowed);

            if ($blocked !== []) {
                throw new RuntimeException(
                    "Report template '{$template->name}' is {$originalStatus->value} and immutable; create a new draft version to change: ".implode(', ', $blocked)
                );
            }
        });

        // Only drafts may be deleted; published and archived versions are the
        // audit record of what was reported.
        static::deleting(function (self $template) {
            if (! $template->isDraft()) {
                throw new RuntimeException(
                    "Report template '{$template->name}' is {$template->statusEnum()->value} and cannot be deleted."
                );
            }
        });
    }

    protected static function newFactory()
    {
        return ReportTemplateFactory::new();
    }
}
