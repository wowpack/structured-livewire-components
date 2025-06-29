<?php

return [
    'livewire-components-directory' => app_path('Livewire'),
    'cache_enabled' => env('STRUCTURED_LIVEWIRE_CACHE', true),
    'groups' => [
        'pages' => [
            'location' => 'Components',
            'suffix' => 'components',
        ],
        'components' => [
            'location' => 'Components',
            'suffix' => 'components',
        ],
    ]
];
