<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;

/**
 * @extends Factory<ReportLineAccount>
 */
class ReportLineAccountFactory extends Factory
{
    protected $model = ReportLineAccount::class;

    public function definition(): array
    {
        return [
            'report_line_id' => ReportLine::factory(),
            'account_id'     => Account::query()->value('id') ?? Account::factory(),
            'sign'           => 1,
        ];
    }

    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'sign' => -1,
        ]);
    }
}
