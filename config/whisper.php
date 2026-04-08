<?php

return [
    'routing' => [
        'mode' => env('WHISPER_MODE', 'path'),
        'prefix' => 'whisper',
    ],

    'guard' => 'web',

    /**
     * Path to ffmpeg / ffprobe (must be installed on the host).
     * Defaults assume they are in PATH.
     */
    'ffmpeg_path' => env('WHISPER_FFMPEG_PATH', 'ffmpeg'),
    'ffprobe_path' => env('WHISPER_FFPROBE_PATH', 'ffprobe'),

    /**
     * If a single audio file exceeds this size, it gets chunked into segments.
     * OpenAI Whisper hard limit = 25 MB. We chunk above 20 MB to be safe.
     */
    'chunk_threshold_bytes' => (int) env('WHISPER_CHUNK_THRESHOLD_BYTES', 20 * 1024 * 1024),

    /**
     * Length of each chunk in seconds when splitting (default 10 min).
     */
    'segment_seconds' => (int) env('WHISPER_SEGMENT_SECONDS', 600),

    /**
     * HTTP timeout in seconds for each Whisper API call.
     */
    'api_timeout' => (int) env('WHISPER_API_TIMEOUT', 600),

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
