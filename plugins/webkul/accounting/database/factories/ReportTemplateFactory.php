<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\CurrencyMode;
use Webkul\Accounting\Enums\EntityMode;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Security\Models\User;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_id'         => null,
            'creator_id'         => User::query()->value('id') ?? User::factory(),
            'parent_template_id' => null,
            'sort'               => fake()->numberBetween(0, 100),
            'name'               => ucwords($name),
            'code'               => str($name)->slug()->toString(),
            'layout_type'        => LayoutType::PERIOD_TOTAL,
            'currency_mode'      => CurrencyMode::LEDGER_ONLY,
            'entity_mode'        => EntityMode::SINGLE_COMPANY,
            'status'             => TemplateStatus::DRAFT,
            'version'            => 1,
            'description'        => null,
        ];
    }

    public function monthlyMatrix(): static
    {
        return $this->state(fn (array $attributes) => [
            'layout_type' => LayoutType::MONTHLY_MATRIX,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TemplateStatus::PUBLISHED,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TemplateStatus::ARCHIVED,
        ]);
    }
}
