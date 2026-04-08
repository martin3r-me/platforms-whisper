<?php

return [
    'routing' => [
        'mode' => env('WHISPER_MODE', 'path'),
        'prefix' => 'whisper',
    ],

    'guard' => 'web',

    /**
     * AssemblyAI - Transcription + Speaker Diarization Provider.
     * Setze ASSEMBLYAI_API_KEY in .env.
     */
    'assemblyai' => [
        'api_key' => env('ASSEMBLYAI_API_KEY'),

        // HTTP-Timeout fuer Upload / Submit / Poll (Sekunden).
        'request_timeout' => (int) env('WHISPER_AAI_REQUEST_TIMEOUT', 120),

        // Polling-Intervall in Sekunden.
        'poll_interval_seconds' => (int) env('WHISPER_AAI_POLL_INTERVAL', 3),

        // Max. Wartezeit fuer Polling (Sekunden).
        'max_wait_seconds' => (int) env('WHISPER_AAI_MAX_WAIT', 1500),

        // Speaker Diarization aktivieren.
        'speaker_labels' => (bool) env('WHISPER_AAI_SPEAKER_LABELS', true),

        // Optional: erwartete Anzahl Sprecher (verbessert Diarization). 0 = automatisch.
        'speakers_expected' => (int) env('WHISPER_AAI_SPEAKERS_EXPECTED', 0),
    ],

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
