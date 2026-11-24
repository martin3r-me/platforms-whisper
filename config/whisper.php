<?php

return [
    'routing' => [
        'mode' => env('WHISPER_MODE', 'path'),
        'prefix' => 'whisper',
    ],

    'guard' => 'web',

    /**
     * Transkription auf dem EIGENEN Server (keine Fremdleistung) — derselbe Dienst,
     * den Parlan nutzt. Jede Instanz kann ihren eigenen Server eintragen.
     *
     *   stt_url          Basis-URL eines OpenAI-kompatiblen Whisper-Servers
     *                    (POST {stt_url}/v1/audio/transcriptions). Leer = keine Transkription:
     *                    Aufnahmen werden gesichert und als failed markiert, bis ein Server
     *                    eingetragen ist (dann: php artisan whisper:retranscribe --failed).
     *   speakers_url     Optional: Text mit Sprechern in einem Aufruf (Whisper + pyannote), volle URL.
     *   diarization_url  Optional: nur Sprechererkennung (pyannote), volle URL. Vertrag siehe
     *                    LocalTranscriptionService::diarize(). Beide leer = ein Sprecher pro Spur.
     *
     *   Kurzform: GPU_GATE_URL + GPU_GATE_KEY setzen — daraus ergeben sich alle Pfade.
     */
    'transcription' => [
        // GPU-Gate (eigene GPU-Box, im VPN, Bearer-Schlüssel): ein Eingang für Whisper large-v3,
        // pyannote und das Sprachmodell. Die einzelnen WHISPER_*-Werte überschreiben es.
        'stt_url' => env('WHISPER_STT_URL') ?: env('GPU_GATE_URL') ?: env('PARLAN_STT_URL', ''),
        'stt_model' => env('WHISPER_STT_MODEL', 'whisper-1'),
        'stt_api_key' => env('WHISPER_STT_API_KEY') ?: env('GPU_GATE_KEY', ''),
        // Text MIT Sprechern in einem Aufruf (Whisper + pyannote auf dem Server zugeordnet) — bevorzugt.
        'speakers_url' => env('WHISPER_SPEAKERS_URL')
            ?: (env('GPU_GATE_URL') ? rtrim(env('GPU_GATE_URL'), '/') . '/v1/audio/transcriptions/speakers' : ''),
        // Nur „wer spricht wann" — Rückfall, falls speakers_url keine Sprecher je Segment liefert.
        'diarization_url' => env('WHISPER_DIARIZATION_URL')
            ?: (env('GPU_GATE_URL') ? rtrim(env('GPU_GATE_URL'), '/') . '/v1/audio/diarize' : ''),
        'diarization_api_key' => env('WHISPER_DIARIZATION_API_KEY') ?: env('GPU_GATE_KEY', ''),
        // HTTP-Timeout je Aufruf (Sekunden) — lange Meetings brauchen Zeit.
        'timeout' => (int) env('WHISPER_STT_TIMEOUT', 1800),
        // Optional: erwartete Anzahl Sprecher (hilft der Diarisierung). 0 = automatisch.
        'speakers_expected' => (int) env('WHISPER_SPEAKERS_EXPECTED', 0),
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
