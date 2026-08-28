<?php

return [
    'navigation' => [
        'title' => 'External Providers',
        'group' => 'Report Administration',
    ],

    'content' => [
        'registered'      => 'Registered providers',
        'none-registered' => 'No external value providers are registered. Register them on the ReportValueProviderRegistry singleton during plugin boot.',
        'lines'           => 'Report lines using external providers',
        'none-used'       => 'No report lines use an external provider.',
        'template'        => 'Template',
        'line'            => 'Line',
        'provider'        => 'Provider key',
        'status'          => 'Status',
        'ok'              => 'Registered',
        'missing'         => 'Not registered',
    ],
];
