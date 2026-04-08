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

        // Speech-Model-Prioritaetsliste (AssemblyAI waehlt den ersten verfuegbaren).
        // Gueltige Werte (Stand 2026): universal-3-pro, universal-2.
        // Via ENV als komma-separierte Liste ueberschreibbar.
        'speech_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WHISPER_AAI_SPEECH_MODELS', 'universal-3-pro,universal-2'))
        ))),
    ],

    /**
     * LeMUR - LLM-Layer ueber dem Transkript. Wird nach erfolgreicher
     * Transkription aufgerufen und erzeugt Titel/Summary/Action Items.
     * Ausserdem steht Q&A fuer das AskRecordingQuestion-Tool zur Verfuegung.
     */
    'lemur' => [
        'enabled' => (bool) env('WHISPER_LEMUR_ENABLED', true),

        // AssemblyAI-LeMUR-Modell. Gueltige Werte (Stand 2026):
        // "default", "anthropic/claude-sonnet-4-5", "anthropic/claude-opus-4-1",
        // "anthropic/claude-haiku-4-5", "anthropic/claude-3-5-sonnet",
        // "anthropic/claude-3-opus", "anthropic/claude-3-haiku".
        'final_model' => env('WHISPER_LEMUR_MODEL', 'default'),

        'max_output_size' => (int) env('WHISPER_LEMUR_MAX_OUTPUT', 2000),
        'temperature' => (float) env('WHISPER_LEMUR_TEMPERATURE', 0.0),
        'request_timeout' => (int) env('WHISPER_LEMUR_TIMEOUT', 120),
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
