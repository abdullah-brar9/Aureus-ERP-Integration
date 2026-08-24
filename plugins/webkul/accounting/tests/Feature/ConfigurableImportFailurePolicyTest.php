<?php

use Webkul\Account\Models\Partner;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function customerVendorAcceptanceCsvPath(): string
{
    $path = getenv('ACCOUNTING_PARTY_CSV_FIXTURE');

    if (! is_string($path) || $path === '' || ! is_file($path)) {
        test()->markTestSkipped('Set ACCOUNTING_PARTY_CSV_FIXTURE to Customers and Vendor List - Sheet2.csv.');
    }

    return $path;
}

function importPolicyCompanyAndUser(): array
{
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);

    return [$company, $user];
}

it('imports the supplied customer and vendor CSV sections without duplicate parties', function (): void {
    [$company, $user] = importPolicyCompanyAndUser();
    $path = customerVendorAcceptanceCsvPath();

    $customerProfile = ImportProfile::query()->create([
        'company_id'    => $company->id,
        'owner_id'      => $user->id,
        'name'          => 'Acceptance customers',
        'entity_type'   => 'customer',
        'file_type'     => 'csv',
        'header_row'    => 3,
        'data_start_row'=> 4,
        'blank_row_rule'=> 'skip',
        'failure_policy'=> 'reject_file',
        'stop_rule'     => ['column' => 'Customer Name', 'operator' => 'blank'],
        'delimiter'     => ',',
        'encoding'      => 'UTF-8',
        'version'       => 1,
        'is_active'     => true,
    ]);
    $customerProfile->mappings()->createMany([
        ['position' => 1, 'source_position' => 1, 'target_field' => 'reference', 'is_required' => true],
        ['position' => 2, 'source_position' => 2, 'target_field' => 'name', 'is_required' => true],
    ]);

    $customerRun = app(ImportPreviewService::class)->preview($customerProfile, $path, basename($path), $user->id);
    expect($customerRun->total_rows)->toBe(4)
        ->and($customerRun->passed_rows)->toBe(4)
        ->and($customerRun->failed_rows)->toBe(0)
        ->and($customerRun->duplicate_rows)->toBe(0);
    $customerRun = app(ImportExecutionService::class)->confirm($customerRun, $user->id);
    expect($customerRun->imported_rows)->toBe(4)
        ->and(Partner::query()->where('company_id', $company->id)->where('sub_type', 'customer')->count())->toBe(4);

    $vendorProfile = ImportProfile::query()->create([
        'company_id'    => $company->id,
        'owner_id'      => $user->id,
        'name'          => 'Acceptance vendors',
        'entity_type'   => 'vendor',
        'file_type'     => 'csv',
        'header_row'    => 18,
        'data_start_row'=> 19,
        'blank_row_rule'=> 'skip',
        'failure_policy'=> 'reject_rows',
        'delimiter'     => ',',
        'encoding'      => 'UTF-8',
        'version'       => 1,
        'is_active'     => true,
    ]);
    $vendorProfile->mappings()->create([
        'position'      => 1,
        'source_header' => 'Vendor List',
        'target_field'  => 'name',
        'is_required'   => true,
    ]);

    $vendorRun = app(ImportPreviewService::class)->preview($vendorProfile, $path, basename($path), $user->id);
    expect($vendorRun->total_rows)->toBe(48)
        ->and($vendorRun->passed_rows)->toBe(34)
        ->and($vendorRun->duplicate_rows)->toBe(14)
        ->and(fn () => app(ImportExecutionService::class)->confirm($vendorRun, $user->id))
        ->toThrow(RuntimeException::class, 'Confirm that detected duplicates');

    $vendorRun = app(ImportExecutionService::class)->confirm($vendorRun, $user->id, true);
    expect($vendorRun->imported_rows)->toBe(34)
        ->and($vendorRun->duplicates_confirmed_at)->not->toBeNull()
        ->and(Partner::query()->where('company_id', $company->id)->where('sub_type', 'vendor')->count())->toBe(34);

    $overlapRun = app(ImportPreviewService::class)->preview($vendorProfile, $path, basename($path), $user->id);
    expect($overlapRun->passed_rows)->toBe(0)
        ->and($overlapRun->duplicate_rows)->toBe(48);
    $overlapRun = app(ImportExecutionService::class)->confirm($overlapRun, $user->id, true);
    expect($overlapRun->imported_rows)->toBe(0)
        ->and(Partner::query()->where('company_id', $company->id)->where('sub_type', 'vendor')->count())->toBe(34);
});

it('imports valid rows and retains rejected rows under the reject-rows policy', function (): void {
    [$company, $user] = importPolicyCompanyAndUser();
    $path = tempnam(sys_get_temp_dir(), 'aureus-import-policy-');
    file_put_contents($path, "Name,Email\nValid Vendor,valid@example.test\n,missing-name@example.test\n");

    try {
        $profile = ImportProfile::query()->create([
            'company_id'    => $company->id,
            'owner_id'      => $user->id,
            'name'          => 'Reject invalid vendor rows',
            'entity_type'   => 'vendor',
            'file_type'     => 'csv',
            'header_row'    => 1,
            'data_start_row'=> 2,
            'blank_row_rule'=> 'skip',
            'failure_policy'=> 'reject_rows',
            'delimiter'     => ',',
            'encoding'      => 'UTF-8',
            'version'       => 1,
            'is_active'     => true,
        ]);
        $profile->mappings()->createMany([
            ['position' => 1, 'source_header' => 'Name', 'target_field' => 'name', 'is_required' => true],
            ['position' => 2, 'source_header' => 'Email', 'target_field' => 'email', 'validation_rules' => ['email']],
        ]);

        $run = app(ImportPreviewService::class)->preview($profile, $path, 'vendors.csv', $user->id);
        expect($run->passed_rows)->toBe(1)
            ->and($run->failed_rows)->toBe(1)
            ->and($run->sourceRows()->where('status', 'error')->first()?->processed_at)->toBeNull();

        $run = app(ImportExecutionService::class)->confirm($run, $user->id);
        expect($run->status)->toBe('completed_with_rejections')
            ->and($run->imported_rows)->toBe(1)
            ->and($run->sourceRows()->where('status', 'error')->first()?->canonical_id)->toBeNull()
            ->and(Partner::query()->where('company_id', $company->id)->where('name', 'Valid Vendor')->exists())->toBeTrue();
    } finally {
        @unlink($path);
    }
});
