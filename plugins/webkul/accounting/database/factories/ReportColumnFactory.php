<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Security\Models\User;

/**
 * @extends Factory<ReportColumn>
 */
class ReportColumnFactory extends Factory
{
    protected $model = ReportColumn::class;

    public function definition(): array
    {
        return [
            'report_template_id' => ReportTemplate::factory(),
            'creator_id'         => User::query()->value('id') ?? User::factory(),
            'company_id'         => null,
            'sort'               => fake()->numberBetween(0, 100),
            'label'              => null,
            'column_type'        => ColumnType::MONTH,
            'start_month'        => fake()->numberBetween(1, 12),
            'end_month'          => null,
            'year_offset'        => 0,
            'is_consolidated'    => false,
        ];
    }

    public function fullYear(): static
    {
        return $this->state(fn (array $attributes) => [
            'column_type' => ColumnType::FULL_YEAR,
            'start_month' => null,
            'end_month'   => null,
        ]);
    }

    public function range(int $startMonth, int $endMonth): static
    {
        return $this->state(fn (array $attributes) => [
            'column_type' => ColumnType::RANGE,
            'start_month' => $startMonth,
            'end_month'   => $endMonth,
        ]);
    }

    public function spacer(): static
    {
        return $this->state(fn (array $attributes) => [
            'column_type' => ColumnType::SPACER,
            'start_month' => null,
            'end_month'   => null,
        ]);
    }

    public function consolidated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_consolidated' => true,
        ]);
    }
}
