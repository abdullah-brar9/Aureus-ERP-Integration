<?php

namespace Webkul\Accounting\Services\Coa;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resolves the many shapes a Filament FileUpload / Livewire upload state can
 * take into one readable, absolute filesystem path — and (for the importer)
 * persists a stable working copy so Preview and Confirm Import always parse the
 * exact same bytes even if Livewire garbage-collects its temporary upload
 * between the two Livewire requests.
 *
 * The state passed in may be, in any combination:
 *   - a Livewire TemporaryUploadedFile   (getRealPath() = livewire-tmp path)
 *   - a Symfony/Laravel UploadedFile      (getRealPath() = raw PHP upload tmp)
 *   - a single value OR an array of them  (FileUpload stores keyed arrays)
 *   - an already-absolute Windows path    (C:\..., C:/..., \\server\share)
 *   - an already-absolute POSIX path      (/var/...)
 *   - a relative Laravel storage path     (coa-imports/hash.csv on the local disk)
 *
 * The previous importer assumed only the last case and wrapped every value in
 * Storage::disk('local')->path(), which turned an absolute upload path such as
 *   C:\Users\HP\AppData\Local\Temp\phpB063.tmp
 * into
 *   storage/app/private/C:\Users\HP\AppData\Local\Temp\phpB063.tmp
 * — a file that can never exist. This resolver is the single, shared place that
 * classifies the path correctly and never double-prefixes an absolute path.
 */
class CoaUploadPathResolver
{
    /** Disk that backs relative storage paths and the persistent working copy. */
    public const DISK = 'local';

    /** Importer-owned working directory, relative to the disk root. */
    public const WORKING_DIR = 'coa-imports/working';

    /** Extensions the downstream reader understands (CSV + XLSX family). */
    protected const SUPPORTED_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls', 'xlsm'];

    /** Delete abandoned working copies older than this many seconds. */
    protected const WORKING_TTL = 21600; // 6 hours

    /**
     * Resolve upload state to a readable, absolute path WITHOUT persisting a
     * copy. Throws {@see CoaUploadException} (a clean, user-facing message) when
     * nothing was uploaded or the file cannot be read.
     */
    public function resolve(mixed $state, ?string $originalName = null): string
    {
        $candidate = $this->firstCandidate($state);

        if ($candidate === null) {
            throw new CoaUploadException('Please choose a Chart of Accounts file to upload first.');
        }

        $path = $this->toAbsolutePath($candidate);

        if ($path === null || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new CoaUploadException(
                'The uploaded file could not be read — it may have expired. Please re-upload the file and try again.'
            );
        }

        return $path;
    }

    /**
     * Resolve AND copy the upload into the importer's working directory,
     * returning the absolute path of the stable copy. The copy keeps the correct
     * extension (from the original filename when available) so the reader still
     * routes CSV vs XLSX correctly even when the upload's own name is a *.tmp.
     *
     * The caller should remember the returned path (e.g. in a Livewire property)
     * and reuse it across Preview → Confirm so both parse identical bytes.
     */
    public function resolveWorkingCopy(mixed $state, ?string $originalName = null): string
    {
        $source = $this->resolve($state, $originalName);

        $this->sweepStaleWorkingCopies();

        File::ensureDirectoryExists($this->workingDirPath());

        $extension = $this->extensionFor($originalName, $source);
        $target = $this->workingDirPath().DIRECTORY_SEPARATOR
            .'coa-'.Str::random(40).($extension !== '' ? '.'.$extension : '');

        if (! @copy($source, $target)) {
            throw new CoaUploadException(
                'The uploaded file could not be prepared for import. Please re-upload the file and try again.'
            );
        }

        return $target;
    }

    /**
     * Whether a previously-persisted working path is still usable (exists and
     * lives inside our working directory — never trust a tampered path).
     */
    public function isUsableWorkingCopy(?string $workingPath): bool
    {
        return $workingPath !== null
            && is_file($workingPath)
            && is_readable($workingPath)
            && $this->isInsideWorkingDir($workingPath);
    }

    /** Delete a working copy we created. No-op for anything outside our dir. */
    public function cleanup(?string $workingPath): void
    {
        if ($workingPath !== null && is_file($workingPath) && $this->isInsideWorkingDir($workingPath)) {
            @unlink($workingPath);
        }
    }

    /**
     * Map a single resolved candidate (uploaded-file object OR string path) to
     * an absolute filesystem path. Existence is NOT checked here — callers do
     * that — so this stays a pure, cross-platform classifier.
     *
     * Public so the path-classification can be unit-tested for every shape.
     */
    public function toAbsolutePath(mixed $candidate): ?string
    {
        // TemporaryUploadedFile extends UploadedFile, so this covers both.
        if ($candidate instanceof UploadedFile) {
            $real = $candidate->getRealPath();

            return ($real !== false && $real !== '') ? $real : $candidate->getPathname();
        }

        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        // Already absolute (Windows drive/UNC or POSIX): return unchanged so we
        // never prepend the storage root to it.
        if ($this->isAbsolutePath($candidate)) {
            return $candidate;
        }

        // Relative → a path on the importer's local storage disk.
        return Storage::disk(self::DISK)->path($candidate);
    }

    /**
     * True for absolute Windows (C:\, C:/, \\server\share) or POSIX (/…) paths.
     * Deliberately OS-independent so Windows paths are detected even when tests
     * run on Linux and vice-versa.
     */
    public function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $path);
    }

    /**
     * Pull the first meaningful value out of FileUpload state, which may be a
     * scalar, an uploaded-file object, or a (possibly nested) keyed array.
     */
    protected function firstCandidate(mixed $state): mixed
    {
        if ($state instanceof UploadedFile) {
            return $state;
        }

        if (is_string($state)) {
            return $state === '' ? null : $state;
        }

        if (is_array($state)) {
            foreach ($state as $value) {
                $candidate = $this->firstCandidate($value);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Choose the extension for the working copy: prefer the client's original
     * filename, fall back to the resolved source path, and only keep an
     * extension the reader actually supports (otherwise let the reader raise its
     * own clear "unsupported file type" error).
     */
    protected function extensionFor(?string $originalName, string $sourcePath): string
    {
        foreach ([$originalName, $sourcePath] as $name) {
            if ($name === null || $name === '') {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
                return $ext;
            }
        }

        // Nothing recognised — keep the source extension so the reader can
        // report an accurate, user-facing "unsupported file type" message.
        return strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    }

    protected function workingDirPath(): string
    {
        return Storage::disk(self::DISK)->path(self::WORKING_DIR);
    }

    protected function isInsideWorkingDir(string $path): bool
    {
        $real = realpath($path);
        $base = realpath($this->workingDirPath());

        if ($real === false || $base === false) {
            return false;
        }

        $real = str_replace('\\', '/', $real);
        $base = rtrim(str_replace('\\', '/', $base), '/').'/';

        return str_starts_with($real, $base);
    }

    /** Best-effort removal of abandoned working copies (crash, tab close, etc.). */
    protected function sweepStaleWorkingCopies(): void
    {
        $dir = $this->workingDirPath();

        if (! is_dir($dir)) {
            return;
        }

        $threshold = time() - self::WORKING_TTL;

        foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'coa-*') as $file) {
            if (is_file($file) && @filemtime($file) !== false && filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }
}
