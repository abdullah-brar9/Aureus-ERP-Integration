<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\Coa\CoaUploadException;
use Webkul\Accounting\Services\Coa\CoaUploadPathResolver;

/*
 * Regression coverage for the Chart-of-Accounts upload-path resolver.
 *
 * The original bug: Preview wrapped EVERY FileUpload state value in
 * Storage::disk('local')->path(), so an absolute Windows temp upload path
 *   C:\Users\HP\AppData\Local\Temp\phpB063.tmp
 * became the impossible
 *   storage/app/private/C:\Users\HP\AppData\Local\Temp\phpB063.tmp
 * and Preview died with a raw "File not found" exception.
 */

beforeEach(function () {
    // Isolate every disk touch from real project storage.
    Storage::fake('local');

    $this->resolver = new CoaUploadPathResolver;
    $this->tempFiles = [];

    // Small helper to drop a real OS-temp file with given contents + extension.
    $this->makeTempFile = function (string $contents, string $ext = 'csv') {
        $base = tempnam(sys_get_temp_dir(), 'coa_test_');
        $path = $base.'.'.$ext;
        @unlink($base); // drop the extension-less stub tempnam created
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    };
});

afterEach(function () {
    foreach ($this->tempFiles as $path) {
        @unlink($path);
    }
});

it('keeps an absolute Windows temp path unchanged (the exact reported bug)', function () {
    $windowsPath = 'C:\\Users\\HP\\AppData\\Local\\Temp\\phpB063.tmp';

    expect($this->resolver->isAbsolutePath($windowsPath))->toBeTrue()
        ->and($this->resolver->toAbsolutePath($windowsPath))->toBe($windowsPath);

    // It must NOT be re-rooted under the storage disk.
    expect($this->resolver->toAbsolutePath($windowsPath))
        ->not->toContain('private')
        ->not->toContain(Storage::disk('local')->path(''));
});

it('detects Windows drive, forward-slash-drive and UNC absolute paths', function () {
    expect($this->resolver->isAbsolutePath('C:\\data\\coa.csv'))->toBeTrue()
        ->and($this->resolver->isAbsolutePath('C:/data/coa.csv'))->toBeTrue()
        ->and($this->resolver->isAbsolutePath('\\\\server\\share\\coa.csv'))->toBeTrue()
        ->and($this->resolver->isAbsolutePath('coa-imports/coa.csv'))->toBeFalse();
});

it('keeps an absolute Linux path unchanged', function () {
    $linuxPath = '/var/tmp/php7f3a.tmp';

    expect($this->resolver->isAbsolutePath($linuxPath))->toBeTrue()
        ->and($this->resolver->toAbsolutePath($linuxPath))->toBe($linuxPath);
});

it('maps a relative storage path onto the local disk exactly once', function () {
    $relative = 'coa-imports/hash-name.csv';

    expect($this->resolver->toAbsolutePath($relative))
        ->toBe(Storage::disk('local')->path($relative));
});

it('resolves a Symfony/Laravel UploadedFile via getRealPath()', function () {
    $tmp = ($this->makeTempFile)("Nature,Code,Title\nB/S,1011,Cash\n");
    $upload = new UploadedFile($tmp, 'accounts.csv', 'text/csv', null, true);

    expect($this->resolver->toAbsolutePath($upload))->toBe($upload->getRealPath())
        ->and(is_file($this->resolver->resolve($upload)))->toBeTrue();
});

it('resolves a Livewire TemporaryUploadedFile via getRealPath()', function () {
    Storage::fake(FileUploadConfiguration::disk()); // 'tmp-for-tests' while testing

    $name = 'coa-'.Str::random(8).'.csv';
    Storage::disk(FileUploadConfiguration::disk())->put('livewire-tmp/'.$name, "Nature,Code,Title\nB/S,1011,Cash\n");

    $temporary = TemporaryUploadedFile::createFromLivewire($name);

    expect($this->resolver->toAbsolutePath($temporary))->toBe($temporary->getRealPath())
        ->and(is_file($this->resolver->toAbsolutePath($temporary)))->toBeTrue();
});

it('unwraps an array upload state (Filament stores a keyed array)', function () {
    $tmp = ($this->makeTempFile)("Nature,Code,Title\nB/S,1011,Cash\n");

    expect($this->resolver->resolve(['01HZ...uuid' => $tmp]))->toBe($tmp);
});

it('throws a clean validation error when nothing was uploaded', function () {
    expect(fn () => $this->resolver->resolve(null))
        ->toThrow(CoaUploadException::class, 'choose a Chart of Accounts file');

    expect(fn () => $this->resolver->resolve([]))
        ->toThrow(CoaUploadException::class);
});

it('throws a clean validation error for a missing absolute path (no raw exception)', function () {
    expect(fn () => $this->resolver->resolve('/no/such/file/coa.csv'))
        ->toThrow(CoaUploadException::class, 'could not be read');
});

it('throws a clean validation error for an unreadable/expired temporary upload', function () {
    $tmp = ($this->makeTempFile)("Nature,Code,Title\nB/S,1011,Cash\n");
    $upload = new UploadedFile($tmp, 'accounts.csv', 'text/csv', null, true);

    @unlink($tmp); // Livewire garbage-collected the temp upload.

    expect(fn () => $this->resolver->resolve($upload))
        ->toThrow(CoaUploadException::class, 'could not be read');
});

it('persists a working copy that keeps the CSV extension even from a .tmp upload', function () {
    $tmp = ($this->makeTempFile)("Nature,Code,Title\nB/S,1011,Cash\n", 'tmp');

    $working = $this->resolver->resolveWorkingCopy($tmp, 'Chart_of_Accounts.csv');

    expect(strtolower(pathinfo($working, PATHINFO_EXTENSION)))->toBe('csv')
        ->and($this->resolver->isUsableWorkingCopy($working))->toBeTrue();

    $this->resolver->cleanup($working);
});

it('keeps preview and confirm import parsing the SAME bytes after the source expires', function () {
    $csv = "Nature,Code,Title\nB/S,1011,Cash\nB/S,1012,Bank\n";
    $tmp = ($this->makeTempFile)($csv, 'csv');

    // Preview: resolve + persist a stable working copy, then the source vanishes.
    $working = $this->resolver->resolveWorkingCopy($tmp, 'accounts.csv');
    @unlink($tmp);

    $previewRows = (new CoaSheetReader)->read($working);

    // Confirm import: the same working path is still usable and yields identical rows.
    expect($this->resolver->isUsableWorkingCopy($working))->toBeTrue();
    $importRows = (new CoaSheetReader)->read($working);

    expect($previewRows)->toBe($importRows)
        ->and($previewRows)->not->toBeEmpty();

    $this->resolver->cleanup($working);
    expect($this->resolver->isUsableWorkingCopy($working))->toBeFalse();
});

it('preserves CSV support end-to-end through a working copy', function () {
    $tmp = ($this->makeTempFile)("Nature,Code,Title\nB/S,1011,Cash\nP&L,4001,Sales\n", 'csv');

    $working = $this->resolver->resolveWorkingCopy($tmp, 'accounts.csv');
    $rows = (new CoaSheetReader)->read($working);

    expect($rows)->toHaveCount(3)
        ->and($rows[1][1])->toBe('1011');

    $this->resolver->cleanup($working);
});

it('preserves XLSX support end-to-end through a working copy', function () {
    $xlsxBase = tempnam(sys_get_temp_dir(), 'coa_test_');
    $xlsxPath = $xlsxBase.'.xlsx';
    @unlink($xlsxBase);
    $this->tempFiles[] = $xlsxPath;

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Nature', 'Code', 'Title'],
        ['B/S', '1011', 'Cash'],
        ['P&L', '4001', 'Sales'],
    ], null, 'A1');
    (new XlsxWriter($spreadsheet))->save($xlsxPath);

    // The upload could arrive with a .tmp name; the original name gives us .xlsx.
    $working = $this->resolver->resolveWorkingCopy($xlsxPath, 'accounts.xlsx');

    expect(strtolower(pathinfo($working, PATHINFO_EXTENSION)))->toBe('xlsx');

    $rows = (new CoaSheetReader)->read($working);
    // PhpSpreadsheet may type the numeric code cell as an int — the downstream
    // parser normalises this; here we only assert the XLSX round-tripped.
    expect($rows)->toHaveCount(3)
        ->and((string) $rows[1][1])->toBe('1011')
        ->and((string) $rows[0][0])->toBe('Nature');

    $this->resolver->cleanup($working);
});

it('never deletes a path outside its own working directory', function () {
    $tmp = ($this->makeTempFile)('keep me', 'csv');

    $this->resolver->cleanup($tmp); // outside the working dir → must be a no-op

    expect(is_file($tmp))->toBeTrue();
});
