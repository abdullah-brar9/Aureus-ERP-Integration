<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Accounting\Filament\Clusters\Configuration\Pages\ImportChartOfAccounts;
use Webkul\Accounting\Services\Coa\CoaUploadPathResolver;

// NOTE: intentionally does NOT use TestBootstrapHelper — that helper runs
// migrate:fresh + erp:install, which would wipe the working database. This test
// only needs a booted admin panel and an authenticated, permissioned user, both
// provided by FilamentHelper. Everything it writes is rolled back by the
// surrounding DatabaseTransactions.
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

/*
 * End-to-end page test: drives the real ImportChartOfAccounts Livewire page the
 * way the browser does, but seeds the upload state with the EXACT shape that
 * broke Preview in production — an absolute OS temp path with a .tmp name, like
 * PHP's C:\Users\HP\AppData\Local\Temp\phpB063.tmp. The old resolveRows() fed
 * that straight into Storage::disk('local')->path(), producing
 * storage/app/private/C:\Users\...\phpB063.tmp and a "File not found" crash.
 *
 * Asserts the same numbers the manual Preview must show: 62 leaf accounts and
 * the sheet's provisional Opening/Movement/Adjustment/Closing totals.
 */

beforeEach(function () {
    // Mark the accounting plugin active so the panel registers its pages. This
    // write happens inside the test transaction and is rolled back afterwards.
    if (Schema::hasTable('plugins')) {
        DB::table('plugins')->updateOrInsert(
            ['name' => 'accounting'],
            ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
        );
    }

    URL::resolveMissingNamedRoutesUsing(fn () => '#');
});

it('previews the real Chart of Accounts upload on the import page from an absolute temp path', function () {
    FilamentHelper::actingAs(['page_accounting_import_chart_of_accounts']);

    // Reproduce the failing input: an absolute temp file named like a raw PHP
    // upload (*.tmp), with the real client filename supplied separately.
    $base = tempnam(sys_get_temp_dir(), 'php');
    $tmp = $base.'.tmp';
    @unlink($base);
    copy(base_path('Chart_of_Accounts_Trial_Balance_Test.csv'), $tmp);

    $page = Livewire::test(ImportChartOfAccounts::class)
        ->set('data.file', $tmp)
        ->set('data.file_original_name', 'Chart_of_Accounts_Trial_Balance_Test.csv')
        ->call('preview');

    $preview = $page->instance()->previewData;

    expect($preview)->not->toBeNull('Preview produced no data — the upload path did not resolve')
        ->and($preview['leaves'])->toBe(62)
        ->and($preview['has_errors'])->toBeFalse();

    $p = $preview['provisional'];
    expect($p['opening_debit'])->toBe(800000.0)
        ->and($p['opening_credit'])->toBe(800000.0)
        ->and($p['movement_debit'])->toBe(685000.0)
        ->and($p['movement_credit'])->toBe(685000.0)
        ->and($p['adjustment_debit'])->toBe(20000.0)
        ->and($p['adjustment_credit'])->toBe(20000.0)
        ->and($p['closing_debit'])->toBe(1170000.0)
        ->and($p['closing_credit'])->toBe(1170000.0);

    // Preview persisted a stable working copy inside the importer's temp dir.
    $working = $page->instance()->workingFilePath;
    expect(app(CoaUploadPathResolver::class)->isUsableWorkingCopy($working))->toBeTrue();

    app(CoaUploadPathResolver::class)->cleanup($working);
    @unlink($tmp);
});
