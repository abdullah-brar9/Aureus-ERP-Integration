<?php

namespace Webkul\Accounting\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Models\ConfigurationAudit;

trait AuditsConfiguration
{
    protected static function bootAuditsConfiguration(): void
    {
        static::created(fn (Model $model) => $model->writeConfigurationAudit('created', null, $model->getAttributes()));

        static::updated(fn (Model $model) => $model->writeConfigurationAudit('updated', $model->getRawOriginal(), $model->getAttributes()));

        static::deleted(fn (Model $model) => $model->writeConfigurationAudit('deleted', $model->getRawOriginal(), null));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function writeConfigurationAudit(string $event, ?array $before, ?array $after): void
    {
        ConfigurationAudit::query()->create([
            'company_id'     => $this->getAttribute('company_id'),
            'actor_id'       => Auth::id(),
            'auditable_type' => $this->getMorphClass(),
            'auditable_id'   => $this->getKey(),
            'event'          => $event,
            'before'         => $before,
            'after'          => $after,
        ]);
    }
}
