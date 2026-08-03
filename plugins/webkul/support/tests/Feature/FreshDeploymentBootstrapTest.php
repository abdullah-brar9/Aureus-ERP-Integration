<?php

use App\Providers\AppServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Accounting\Filament\Clusters\Accounting\Pages\ImportBankStatement;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\JournalEntryResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CurrencyResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\GeneralLedger;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\TrialBalance;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\PluginManager\Database\Seeders\PluginSeeder;
use Webkul\PluginManager\FreshPluginStates;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

afterEach(function (): void {
    URL::forceScheme(null);
    URL::forceRootUrl(null);
});

it('defines the fresh plugin states used by the local ERP', function () {
    $states = FreshPluginStates::all();

    expect($states)->toHaveCount(19)
        ->and(collect($states)->where('is_installed', true))->toHaveCount(16)
        ->and(collect($states)->where('is_installed', false)->keys()->all())
        ->toBe(['barcode', 'blogs', 'website']);

    foreach ($states as $state) {
        expect($state['is_active'])->toBeTrue();
    }
});

it('initializes missing plugins without changing existing plugin states', function () {
    DB::table('plugins')->where('name', 'barcode')->update([
        'is_active'    => false,
        'is_installed' => true,
    ]);

    DB::table('plugins')->where('name', 'website')->delete();

    $migration = require base_path(
        'plugins/webkul/plugin-manager/database/migrations/2026_08_03_093620_register_bundled_plugins_for_fresh_deployments.php',
    );

    $migration->up();
    (new PluginSeeder)->run();

    $barcode = DB::table('plugins')->where('name', 'barcode')->first();
    $website = DB::table('plugins')->where('name', 'website')->first();

    expect((bool) $barcode->is_active)->toBeFalse()
        ->and((bool) $barcode->is_installed)->toBeTrue()
        ->and((bool) $website->is_active)->toBeTrue()
        ->and((bool) $website->is_installed)->toBeFalse();
});

it('only forces https when the environment-backed flag is enabled', function () {
    config()->set('app.url', 'http://127.0.0.1:8080');
    URL::forceRootUrl(config('app.url'));
    URL::forceScheme(null);

    config()->set('app.force_https', false);
    (new AppServiceProvider(app()))->boot();

    expect(URL::to('/'))->toStartWith('http://');

    config()->set('app.force_https', true);
    (new AppServiceProvider(app()))->boot();

    expect(URL::to('/'))->toStartWith('https://');
});

it('seeds reference data and permissions without creating business records', function () {
    $businessTables = [
        'companies',
        'accounts_journals',
        'accounts_account_moves',
        'accounting_coa_import_batches',
    ];

    $before = collect($businessTables)->mapWithKeys(
        fn (string $table): array => [$table => DB::table($table)->count()],
    );

    $this->seed(DatabaseSeeder::class);

    $after = collect($businessTables)->mapWithKeys(
        fn (string $table): array => [$table => DB::table($table)->count()],
    );

    $adminRole = Role::query()
        ->where('name', 'Admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    $accountingPermissions = Permission::query()
        ->whereIn('name', AccountingPermissions::all())
        ->pluck('name');

    expect($after->all())->toBe($before->all())
        ->and($accountingPermissions)->toHaveCount(count(array_unique(AccountingPermissions::all())))
        ->and($adminRole->permissions()->count())
        ->toBe(Permission::query()->where('guard_name', 'web')->count());
});

it('shows Accounting pages and resources to an administrator', function () {
    $this->seed(DatabaseSeeder::class);

    $company = Company::factory()->create(['is_active' => true]);
    $administrator = User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]);

    $administrator->allowedCompanies()->sync([$company->id]);
    $administrator->assignRole(
        Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail(),
    );

    $this->actingAs($administrator);

    expect(ImportBankStatement::canAccess())->toBeTrue()
        ->and(GeneralLedger::canAccess())->toBeTrue()
        ->and(TrialBalance::canAccess())->toBeTrue()
        ->and(BankStatementResource::canViewAny())->toBeTrue()
        ->and(BankTransactionMappingResource::canViewAny())->toBeTrue()
        ->and(JournalEntryResource::canViewAny())->toBeTrue()
        ->and(CurrencyResource::canViewAny())->toBeTrue()
        ->and(FsTagResource::canViewAny())->toBeTrue();
});
