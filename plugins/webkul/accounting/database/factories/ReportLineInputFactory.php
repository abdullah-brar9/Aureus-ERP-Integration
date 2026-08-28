<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Security\Models\User;

/**
 * @extends Factory<ReportLineInput>
 */
class ReportLineInputFactory extends Factory
{
    protected $model = ReportLineInput::class;

    public function definition(): array
    {
        return [
            'report_line_id' => ReportLine::factory(),
            'company_id'     => null,
            'creator_id'     => User::query()->value('id') ?? User::factory(),
            'date'           => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'value'          => fake()->randomFloat(2, 0, 10000),
        ];
    }
}
