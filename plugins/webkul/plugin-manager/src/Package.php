<?php

namespace Webkul\PluginManager;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package as BasePackage;
use Throwable;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Models\Plugin;

class Package extends BasePackage
{
    public static $plugins = [];

    public ?Plugin $plugin = null;

    public bool $isCore = false;

    public bool $runsSettings = false;

    public array $settingFileNames = [];

    public array $dependencies = [];

    public bool $runsSeeders = false;

    public array $seederClasses = [];

    public ?string $icon = null;

    public function hasInstallCommand($callable): static
    {
        $installCommand = new InstallCommand($this);

        $callable($installCommand);

        $this->consoleCommands[] = $installCommand;

        return $this;
    }

    public function hasUninstallCommand($callable): static
    {
        $uninstallCommand = new UninstallCommand($this);

        $callable($uninstallCommand);

        $this->consoleCommands[] = $uninstallCommand;

        return $this;
    }

    public function isCore(bool $isCore = true): static
    {
        $this->isCore = $isCore;

        return $this;
    }

    public function runsSettings(bool $runsSettings = true): static
    {
        $this->runsSettings = $runsSettings;

        return $this;
    }

    public function hasSetting(string $settingFileName): static
    {
        $this->settingFileNames[] = $settingFileName;

        return $this;
    }

    public function hasSettings(...$settingFileNames): static
    {
        $this->settingFileNames = array_merge(
            $this->settingFileNames,
            collect($settingFileNames)->flatten()->toArray()
        );

        return $this;
    }

    public function runsSeeders(bool $runsSeeders = true): static
    {
        $this->runsSeeders = $runsSeeders;

        return $this;
    }

    public function hasSeeder(string $seederClass): static
    {
        $this->seederClasses[] = $seederClass;

        return $this;
    }

    public function hasSeeders(...$seederClasses): static
    {
        $this->seederClasses = array_merge(
            $this->seederClasses,
            collect($seederClasses)->flatten()->toArray()
        );

        return $this;
    }

    public function hasDependency(string $dependency): static
    {
        $this->dependencies[] = $dependency;

        return $this;
    }

    public function hasDependencies(...$dependencies): static
    {
        $this->dependencies = array_merge(
            $this->dependencies,
            collect($dependencies)->flatten()->toArray()
        );

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function delete(): void
    {
        Plugin::where('name', $this->name)->delete();

        unset(static::$plugins[$this->name]);

        $this->plugin = null;
    }

    public function updateOrCreate(): Plugin
    {
        $composerPath = $this->basePath('../composer.json');
        $composerData = json_decode(file_get_contents($composerPath), true);

        $this->plugin = Plugin::updateOrCreate([
            'name' => $this->name,
        ], [
            'author'         => $composerData['authors'][0]['name'] ?? null,
            'summary'        => $composerData['description'] ?? null,
            'description'    => $composerData['description'] ?? null,
            'latest_version' => $this->version ?? null,
            'license'        => $composerData['license'] ?? null,
            'sort'           => $this->sort ?? null,
            'is_active'      => true,
            'is_installed'   => true,
        ]);

        static::$plugins[$this->name] = $this->plugin;

        return $this->plugin;
    }

    public function getPlugin(): ?Plugin
    {
        if ($this->plugin) {
            return $this->plugin;
        }

        return $this->plugin = static::getPackagePlugin($this->name);
    }

    public function isInstalled(): bool
    {
        return static::isPluginInstalled($this->name);
    }

    public static function getPackagePlugin(string $name): ?Plugin
    {
        if (count(static::$plugins) == 0) {
            if (Schema::hasTable('plugins') === false) {
                return null;
            }

            static::$plugins = Plugin::all()->keyBy('name');
        }

        if (isset(static::$plugins[$name])) {
            return static::$plugins[$name];
        }

        return static::$plugins[$name] ??= Plugin::where('name', $name)->first();
    }


    public static function refreshPluginCaches(): void
    {
        try {
            Artisan::call('optimize:clear');

            if (app()->isProduction()) {
                static::rebuildCachesInBackground();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected static function rebuildCachesInBackground(): void
    {
        if (! app()->isProduction() || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $command = sprintf(
            '%s %s optimize > /dev/null 2>&1 &',
            escapeshellarg(static::phpBinaryPath()),
            escapeshellarg(base_path('artisan'))
        );

        exec($command);
    }

    public static function phpBinaryPath(): string
    {
        // PHP_BINARY only reliably points at the interpreter itself under the
        // CLI SAPI (e.g. `php artisan serve`). Under apache2handler (Laragon's
        // default Apache+mod_php setup), PHP_BINARY resolves to httpd.exe —
        // never trust it outside CLI.
        if (PHP_SAPI === 'cli' && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // PHP_BINDIR is correct under every SAPI (cli, apache2handler, fpm-fcgi).
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $bindirCandidate = rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . $binaryName;

        if (is_file($bindirCandidate)) {
            return $bindirCandidate;
        }

        // Windows has no `which` — must use `where`.
        $locatorCommand = PHP_OS_FAMILY === 'Windows' ? 'where php' : 'which php';
        $found = trim((string) @shell_exec($locatorCommand . ' 2>&1'));
        $found = strtok($found, "\r\n") ?: '';

        if ($found !== '' && is_file($found) && ! str_contains(strtolower($found), 'httpd') && ! str_contains(strtolower($found), 'apache')) {
            return $found;
        }

        $candidates = [
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/homebrew/bin/php',
            '/Users/' . get_current_user() . '/Library/Application Support/Herd/bin/php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $binaryName;
    }

    public static function isPluginInstalled(string $name): bool
    {
        static $isLoaded = false;

        try {
            if (! $isLoaded) {
                DB::connection()->getPdo();

                if (Schema::hasTable('plugins') === false) {
                    $isLoaded = true;
                    return false;
                }

                static::$plugins = Plugin::all()->keyBy('name');
                $isLoaded = true;
            }

            if (isset(static::$plugins[$name]) && static::$plugins[$name]->is_installed) {
                return true;
            }

            return false;
        } catch (Exception) {
            return false;
        }
    }
}
