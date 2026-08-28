<?php

namespace Webkul\Accounting\Models\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Chatter\Traits\HasLogActivity;

/**
 * Shared behaviour for the reporting models:
 *
 *  - audit history: chatter activity messages (user, timestamp, old/new
 *    values) are attached to the owning template, so its timeline shows every
 *    structural change. Logging only happens for authenticated actions —
 *    system writes (seeder, CLI) have no causer and the chatter schema
 *    requires one;
 *  - structural immutability: once the owning template is published (or
 *    archived), creating/editing/deleting the record is rejected — a new
 *    draft version must be created instead. Models that must stay editable
 *    after publishing (manual value entry) set $guardedByTemplateStatus
 *    to false, as does ReportTemplate itself (its own boot enforces the
 *    template-level rules).
 */
trait InteractsWithReportTemplate
{
    use HasLogActivity {
        logModelActivity as protected baseLogModelActivity;
    }

    /**
     * The template this record ultimately belongs to.
     */
    abstract public function owningTemplate(): ?ReportTemplate;

    public function chatterMessageOwner(): ?Model
    {
        return $this->owningTemplate() ?? $this;
    }

    public function logModelActivity(string $event): ?Model
    {
        $causer = Filament::auth()->user() ?? Auth::user();

        if ($causer === null) {
            return null;
        }

        return $this->baseLogModelActivity($event);
    }

    protected function guardedByTemplateStatus(): bool
    {
        return property_exists($this, 'guardedByTemplateStatus')
            ? (bool) $this->guardedByTemplateStatus
            : true;
    }

    public static function bootInteractsWithReportTemplate(): void
    {
        $guard = function (Model $model, string $action): void {
            /** @var self $model */
            if (! $model->guardedByTemplateStatus()) {
                return;
            }

            $template = $model->owningTemplate();

            if ($template !== null && ! $template->isDraft()) {
                throw new RuntimeException(
                    "Report template '{$template->name}' is {$template->statusEnum()->value} and immutable; create a new draft version before trying to {$action} its structure."
                );
            }
        };

        static::saving(fn (Model $model) => $guard($model, 'change'));
        static::deleting(fn (Model $model) => $guard($model, 'delete'));
    }
}
