<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Security\Models\User;

/**
 * @extends Factory<ReportLine>
 */
class ReportLineFactory extends Factory
{
    protected $model = ReportLine::class;

    public function definition(): array
    {
        return [
            'report_template_id' => ReportTemplate::factory(),
            'parent_id'          => null,
            'creator_id'         => User::query()->value('id') ?? User::factory(),
            'sort'               => fake()->numberBetween(0, 100),
            'line_type'          => LineType::DETAIL,
            'caption'            => ucwords(fake()->words(2, true)),
            'code'               => null,
            'sign'               => 1,
            'is_visible'         => true,
            'is_bold'            => false,
            'indent_level'       => 0,
            'dimension_type'     => null,
            'dimension_id'       => null,
        ];
    }

    public function sectionHeader(): static
    {
        return $this->state(fn (array $attributes) => [
            'line_type' => LineType::SECTION_HEADER,
            'is_bold'   => true,
        ]);
    }

    public function subtotal(): static
    {
        return $this->state(fn (array $attributes) => [
            'line_type' => LineType::SUBTOTAL,
            'is_bold'   => true,
        ]);
    }

    public function spacer(): static
    {
        return $this->state(fn (array $attributes) => [
            'line_type' => LineType::SPACER,
            'caption'   => null,
        ]);
    }
}
