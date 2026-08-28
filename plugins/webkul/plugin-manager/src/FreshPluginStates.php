<?php

namespace Webkul\PluginManager;

final class FreshPluginStates
{
    /**
     * @var array<string, array{is_active: bool, is_installed: bool}>
     */
    private const STATES = [
        'accounting'    => ['is_active' => true, 'is_installed' => true],
        'accounts'      => ['is_active' => true, 'is_installed' => true],
        'barcode'       => ['is_active' => true, 'is_installed' => false],
        'blogs'         => ['is_active' => true, 'is_installed' => false],
        'contacts'      => ['is_active' => true, 'is_installed' => true],
        'employees'     => ['is_active' => true, 'is_installed' => true],
        'inventories'   => ['is_active' => true, 'is_installed' => true],
        'invoices'      => ['is_active' => true, 'is_installed' => true],
        'maintenance'   => ['is_active' => true, 'is_installed' => true],
        'manufacturing' => ['is_active' => true, 'is_installed' => true],
        'payments'      => ['is_active' => true, 'is_installed' => true],
        'products'      => ['is_active' => true, 'is_installed' => true],
        'projects'      => ['is_active' => true, 'is_installed' => true],
        'purchases'     => ['is_active' => true, 'is_installed' => true],
        'recruitments'  => ['is_active' => true, 'is_installed' => true],
        'sales'         => ['is_active' => true, 'is_installed' => true],
        'time-off'      => ['is_active' => true, 'is_installed' => true],
        'timesheets'    => ['is_active' => true, 'is_installed' => true],
        'website'       => ['is_active' => true, 'is_installed' => false],
    ];

    /**
     * @return array<string, array{is_active: bool, is_installed: bool}>
     */
    public static function all(): array
    {
        return self::STATES;
    }

    /**
     * @return array{is_active: bool, is_installed: bool}
     */
    public static function for(string $plugin): array
    {
        return self::STATES[$plugin] ?? [
            'is_active'    => true,
            'is_installed' => false,
        ];
    }
}
