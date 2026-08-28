<?php

namespace Webkul\PluginManager\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\PluginManager\FreshPluginStates;
use Webkul\PluginManager\Models\Plugin;

class PluginSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Plugin::getAllPluginPackages() as $pluginName => $package) {
            $composerPath = $package->basePath('composer.json');

            $composerData = [];

            if (file_exists($composerPath)) {
                $composerData = json_decode(file_get_contents($composerPath), true);
            }

            $plugin = Plugin::firstOrNew(['name' => $pluginName]);

            $plugin->fill([
                'author'         => $composerData['authors'][0]['name'] ?? 'Webkul',
                'summary'        => $composerData['description'] ?? $package->description ?? '',
                'description'    => $composerData['description'] ?? $package->description ?? '',
                'latest_version' => $composerData['version'] ?? '1.0.0',
                'license'        => $composerData['license'] ?? 'MIT',
                'sort'           => $plugin->sort ?? 1,
            ]);

            if (! $plugin->exists) {
                $plugin->fill(FreshPluginStates::for($pluginName));
            }

            $plugin->save();
        }
    }
}
