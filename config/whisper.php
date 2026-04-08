<?php

return [
    'routing' => [
        'mode' => env('WHISPER_MODE', 'path'),
        'prefix' => 'whisper',
    ],

    'guard' => 'web',

    'navigation' => [
        'route' => 'whisper.dashboard',
        'icon'  => 'heroicon-o-microphone',
        'order' => 100,
    ],

    'sidebar' => [
        [
            'group' => 'Allgemein',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'whisper.dashboard',
                    'icon'  => 'heroicon-o-home',
                ],
            ],
        ],
    ],
];
