<?php

use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\AccountBindingService;

it('resolves a single bound account', function () {
    $template = ReportTemplate::factory()->create();
    $account  = Account::factory()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id,
        'line_type'          => 'detail',
    ]);

    ReportLineAccount::factory()->create([
        'report_line_id' => $line->id,
        'account_id'     => $account->id,
        'sign'           => 1,
    ]);

    $service = new AccountBindingService(includeDescendants: false);

    $signed = $service->resolveSignedAccounts($line->fresh());

    expect($signed)->toBe([$account->id => 1]);
});

it('expands a parent account to include its descendants', function () {
    $template = ReportTemplate::factory()->create();

    $parent = Account::factory()->create();
    $childA = Account::factory()->create(['parent_id' => $parent->id]);
    $childB = Account::factory()->create(['parent_id' => $parent->id]);
    $grand  = Account::factory()->create(['parent_id' => $childA->id]);

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id,
        'line_type'          => 'detail',
    ]);

    ReportLineAccount::factory()->create([
        'report_line_id' => $line->id,
        'account_id'     => $parent->id,
        'sign'           => 1,
    ]);

    $service = new AccountBindingService(includeDescendants: true);

    $ids = $service->resolveAccountIds($line->fresh());

    expect($ids)->toContain($parent->id)
        ->toContain($childA->id)
        ->toContain($childB->id)
        ->toContain($grand->id)
        ->toHaveCount(4);
});

it('carries a negative binding sign', function () {
    $template = ReportTemplate::factory()->create();
    $account  = Account::factory()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id,
        'line_type'          => 'detail',
    ]);

    ReportLineAccount::factory()->create([
        'report_line_id' => $line->id,
        'account_id'     => $account->id,
        'sign'           => -1,
    ]);

    $service = new AccountBindingService(includeDescendants: false);

    $signed = $service->resolveSignedAccounts($line->fresh());

    expect($signed[$account->id])->toBe(-1);
});
