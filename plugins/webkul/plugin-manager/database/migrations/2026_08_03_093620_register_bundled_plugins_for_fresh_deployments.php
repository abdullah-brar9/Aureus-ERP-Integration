<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Webkul\PluginManager\FreshPluginStates;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('plugins')->insertOrIgnore(array_map(
            fn (string $name, array $state, int $index): array => [
                'name'         => $name,
                'author'       => 'Webkul',
                'is_active'    => $state['is_active'],
                'is_installed' => $state['is_installed'],
                'sort'         => $index + 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            array_keys(FreshPluginStates::all()),
            array_values(FreshPluginStates::all()),
            range(0, count(FreshPluginStates::all()) - 1),
        ));
    }

    public function down(): void {}
};
