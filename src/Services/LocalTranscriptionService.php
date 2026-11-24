<?php

namespace Platform\Whisper\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transkription auf dem EIGENEN Server — keine Fremdleistung.
 *
 *   Spracherkennung  OpenAI-kompatibler Whisper-Dienst (POST {stt_url}/v1/audio/transcriptions),
 *                    derselbe, den Parlan nutzt. Leer = keine Transkription auf dieser Instanz.
 *   Sprecher         optionaler Diarisierungs-Dienst (pyannote), siehe diarize(). Ohne ihn ist eine
 *                    Mono-Aufnahme ein Sprecher; Dual-Spur (Mac) trennt mic/loopback über die Kanäle.
 *
 * Whisper liefert Wörter mit Zeitstempeln, pyannote liefert Sprecher-Zeitfenster; jedes Wort
 * bekommt den Sprecher mit der größten Überlappung, aufeinanderfolgende Wörter desselben
 * Sprechers werden zu einer Äußerung. Ausgabe im internen Format des TranscribeRecordingJob.
 */
class LocalTranscriptionService
{
    /** Typische Whisper-Halluzinationen auf Stille/Rauschen (wie Parlans HuddleScribe). */
    private const NOISE = [
        'untertitel im auftrag des zdf', 'untertitel der amara.org-community', 'vielen dank fürs zuschauen',
        'danke fürs zuschauen', 'untertitelung', 'swr 2020', 'copyright wdr',
    ];

    /** Whisper meldet die Sprache teils ausgeschrieben ("german"). */
    private const LANGUAGES = [
        'german' => 'de', 'english' => 'en', 'french' => 'fr', 'spanish' => 'es', 'italian' => 'it',
        'dutch' => 'nl', 'polish' => 'pl', 'turkish' => 'tr', 'portuguese' => 'pt',
    ];

    public function __construct(protected WavStereoMerger $wav)
    {
    }

    public function isConfigured(): bool
    {
        return $this->sttUrl() !== '';
    }

    /** Erkennt dieser Server Sprecher (pyannote eingerichtet)? */
    public function diarizes(): bool
    {
        return $this->isConfigured()
            && ((string) config('whisper.transcription.speakers_url', '') !== ''
                || (string) config('whisper.transcription.diarization_url', '') !== '');
    }

    /**
     * @return array{
     *     transcript: string, language: ?string, duration: ?float,
     *     segments: array<int, array{speaker: string, start: float, end: float, text: string}>,
     *     speakers_count: int, model: string, provider_id: ?string,
     * }
     */
    public function transcribe(string $filePath, ?string $language = 'de', bool $multichannel = false): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Keine Transkription eingerichtet (WHISPER_STT_URL fehlt).');
        }
        if (!is_file($filePath)) {
            throw new RuntimeException('Audio-Datei nicht gefunden: ' . basename($filePath));
        }
        $language = ($language === null || $language === 'auto') ? null : $language;

        return $multichannel
            ? $this->transcribeDual($filePath, $language)
            : $this->transcribeMono($filePath, $language);
    }

    protected function transcribeMono(string $path, ?string $language): array
    {
        $labels = [];
        $part = $this->transcribeChannel($path, $language, $labels, 'A');

        return $this->result($part['segments'], $part['language'], $part['duration'], $part['text']);
    }

    /**
     * Eine Spur → Äußerungen mit Sprechern. Bevorzugt der kombinierte Server-Aufruf
     * (speakers_url: Whisper + pyannote dort zugeordnet); liefert der keine Sprecher je
     * Segment, ordnet Whisper selbst zu (Text-Aufruf + diarize). $diarize=false: alles $fallback.
     *
     * @param  array<string, string>  $labels
     * @return array{segments: array, language: ?string, duration: ?float, text: string}
     */
    protected function transcribeChannel(string $path, ?string $language, array &$labels, string $fallback, bool $diarize = true): array
    {
        if ($diarize && ($combined = $this->speakers($path, $language)) !== null) {
            $segments = [];
            foreach ($combined['turns'] as $t) {
                $speaker = $this->label($t['speaker'], $labels, $fallback);
                $last = $segments === [] ? null : count($segments) - 1;
                if ($last !== null && $segments[$last]['speaker'] === $speaker && $t['start'] - $segments[$last]['end'] < 2.0) {
                    $segments[$last]['text'] .= ' ' . $t['text'];
                    $segments[$last]['end'] = $t['end'];
                } else {
                    $segments[] = ['speaker' => $speaker, 'start' => $t['start'], 'end' => $t['end'], 'text' => $t['text']];
                }
            }

            return ['segments' => $segments] + $combined;
        }

        $stt = $this->stt($path, $language);
        $turns = $diarize && (string) config('whisper.transcription.diarization_url', '') !== ''
            ? $this->diarize($path)
            : [];

        return [
            'segments' => $this->utterances($stt['units'], $turns, $labels, $fallback),
            'language' => $stt['language'],
            'duration' => $stt['duration'],
            'text' => $stt['text'],
        ];
    }

    /**
     * Kombinierter Aufruf (Whisper + pyannote auf dem Server). Null, wenn nicht eingerichtet
     * oder die Antwort keine Sprecher je Segment enthält — dann ordnet Whisper selbst zu.
     * Liest Segmente tolerant: segments|utterances|Liste, speaker|speaker_label,
     * start/end in Sekunden (oder start_ms/end_ms), text|content.
     *
     * @return array{turns: array<int, array{start: float, end: float, speaker: string, text: string}>, language: ?string, duration: ?float, text: string}|null
     */
    protected function speakers(string $path, ?string $language): ?array
    {
        $url = (string) config('whisper.transcription.speakers_url', '');
        if ($url === '') {
            return null;
        }

        $parts = [['name' => 'model', 'contents' => (string) config('whisper.transcription.stt_model', 'whisper-1')]];
        if ($language) {
            $parts[] = ['name' => 'language', 'contents' => $language];
        }
        if (($n = (int) config('whisper.transcription.speakers_expected', 0)) > 0) {
            $parts[] = ['name' => 'num_speakers', 'contents' => (string) $n];
        }

        $res = $this->client((string) config('whisper.transcription.stt_api_key', ''))
            ->attach('file', fopen($path, 'rb'), basename($path))
            ->post($url, $parts);
        if (!$res->successful()) {
            throw new RuntimeException('Spracherkennung mit Sprechern fehlgeschlagen (HTTP ' . $res->status() . '): ' . mb_substr($res->body(), 0, 300));
        }
        $data = (array) $res->json();
        $raw = $data['segments'] ?? $data['utterances'] ?? (array_is_list($data) ? $data : []);

        $turns = [];
        foreach ((array) $raw as $seg) {
            $speaker = $seg['speaker'] ?? $seg['speaker_label'] ?? null;
            $text = trim((string) ($seg['text'] ?? $seg['content'] ?? ''));
            if ($speaker === null || $text === '' || $this->isNoise($text)) {
                continue;
            }
            $start = isset($seg['start']) ? (float) $seg['start'] : ((float) ($seg['start_ms'] ?? 0)) / 1000;
            $end = isset($seg['end']) ? (float) $seg['end'] : ((float) ($seg['end_ms'] ?? 0)) / 1000;
            $turns[] = ['start' => $start, 'end' => $end, 'speaker' => (string) $speaker, 'text' => $text];
        }
        if ($turns === []) {
            return null;
        }

        $text = trim((string) ($data['text'] ?? ''));

        return [
            'turns' => $turns,
            'language' => $this->language($data['language'] ?? null),
            'duration' => isset($data['duration']) ? (float) $data['duration'] : null,
            'text' => $this->isNoise($text) ? '' : $text,
        ];
    }

    /**
     * Dual-Spur (Mac): links = mic (der Nutzer selbst, "A"), rechts = loopback (die Gegenseite).
     * Die Gegenseite kann mehrere Personen sein — mit pyannote wird sie weiter aufgeteilt (B, C, …).
     */
    protected function transcribeDual(string $path, ?string $language): array
    {
        $mic = RecordingAudioStore::tmpPath('wav');
        $loop = RecordingAudioStore::tmpPath('wav');
        try {
            $this->wav->split($path, $mic, $loop);

            $labels = ['mic' => 'A'];
            $micPart = $this->transcribeChannel($mic, $language, $labels, 'A', diarize: false);
            $loopPart = $this->transcribeChannel($loop, $language ?? $micPart['language'], $labels, 'B');
            $segments = array_merge($micPart['segments'], $loopPart['segments']);
            usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);

            $duration = max((float) ($micPart['duration'] ?? 0), (float) ($loopPart['duration'] ?? 0)) ?: null;

            return $this->result($segments, $micPart['language'] ?? $loopPart['language'], $duration, null);
        } finally {
            @unlink($mic);
            @unlink($loop);
        }
    }

    /**
     * Whisper-Aufruf. Einheiten sind Wörter (wenn der Server sie liefert) sonst Segmente.
     *
     * @return array{text: string, language: ?string, duration: ?float, units: array<int, array{start: float, end: float, text: string}>}
     */
    protected function stt(string $path, ?string $language): array
    {
        $parts = [
            ['name' => 'model', 'contents' => (string) config('whisper.transcription.stt_model', 'whisper-1')],
            ['name' => 'response_format', 'contents' => 'verbose_json'],
            ['name' => 'timestamp_granularities[]', 'contents' => 'word'],
            ['name' => 'timestamp_granularities[]', 'contents' => 'segment'],
        ];
        if ($language) {
            $parts[] = ['name' => 'language', 'contents' => $language];
        }

        $res = $this->client((string) config('whisper.transcription.stt_api_key', ''))
            ->attach('file', fopen($path, 'rb'), basename($path))
            ->post($this->sttUrl() . '/v1/audio/transcriptions', $parts);

        if (!$res->successful()) {
            throw new RuntimeException('Spracherkennung fehlgeschlagen (HTTP ' . $res->status() . '): ' . mb_substr($res->body(), 0, 300));
        }
        $data = (array) $res->json();

        // Halluzinierte Segmente (Stille/Rauschen) samt ihrer Wörter verwerfen.
        $segments = [];
        $noise = [];
        foreach ((array) ($data['segments'] ?? []) as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            $window = ['start' => (float) ($seg['start'] ?? 0), 'end' => (float) ($seg['end'] ?? 0), 'text' => $text];
            if ($this->isNoise($text)) {
                $noise[] = $window;
            } elseif ($text !== '') {
                $segments[] = $window;
            }
        }

        $units = [];
        foreach ((array) ($data['words'] ?? []) as $w) {
            $text = trim((string) ($w['word'] ?? $w['text'] ?? ''));
            $start = (float) ($w['start'] ?? 0);
            $end = (float) ($w['end'] ?? 0);
            $mid = ($start + $end) / 2;
            $inNoise = array_filter($noise, fn ($n) => $mid >= $n['start'] && $mid <= $n['end']) !== [];
            if ($text !== '' && !$inNoise) {
                $units[] = ['start' => $start, 'end' => $end, 'text' => $text];
            }
        }
        if ($units === [] && empty($data['words'])) {
            $units = $segments;
        }
        $text = trim((string) ($data['text'] ?? ''));
        if ($units === [] && $text !== '' && !$this->isNoise($text)) {
            $units[] = ['start' => 0.0, 'end' => (float) ($data['duration'] ?? 0), 'text' => $text];
        }

        return [
            'text' => $this->isNoise($text) ? '' : $text,
            'language' => $this->language($data['language'] ?? null),
            'duration' => isset($data['duration']) ? (float) $data['duration'] : null,
            'units' => $units,
        ];
    }

    /**
     * Sprecher-Zeitfenster vom Diarisierungs-Dienst (pyannote).
     *
     * Vertrag: POST {diarization_url}, multipart `file` (+ optional `num_speakers`), Antwort JSON
     *   {"segments": [{"start": 0.5, "end": 3.2, "speaker": "SPEAKER_00"}, …]}
     * Liefert der Dienst zusätzlich `exclusive_segments` (pyannote community-1: höchstens ein
     * Sprecher gleichzeitig), werden diese bevorzugt — sie passen besser zu Whispers Wörtern.
     *
     * @return array<int, array{start: float, end: float, speaker: string}>
     */
    protected function diarize(string $path): array
    {
        $parts = [];
        if (($n = (int) config('whisper.transcription.speakers_expected', 0)) > 0) {
            $parts[] = ['name' => 'num_speakers', 'contents' => (string) $n];
        }

        $res = $this->client((string) config('whisper.transcription.diarization_api_key', ''))
            ->attach('file', fopen($path, 'rb'), basename($path))
            ->post((string) config('whisper.transcription.diarization_url'), $parts);

        if (!$res->successful()) {
            throw new RuntimeException('Sprechererkennung fehlgeschlagen (HTTP ' . $res->status() . '): ' . mb_substr($res->body(), 0, 300));
        }
        $data = $res->json();
        $raw = $data['exclusive_segments'] ?? $data['segments'] ?? (array_is_list((array) $data) ? $data : []);

        $turns = [];
        foreach ((array) $raw as $t) {
            if (isset($t['start'], $t['end'], $t['speaker'])) {
                $turns[] = ['start' => (float) $t['start'], 'end' => (float) $t['end'], 'speaker' => (string) $t['speaker']];
            }
        }

        return $turns;
    }

    /**
     * Wörter/Segmente → Äußerungen. Ohne Sprecher-Zeitfenster gehört alles $fallback.
     * $labels mappt Diarisierungs-Sprecher auf A, B, C… (über Kanäle hinweg geteilt).
     *
     * @param  array<int, array{start: float, end: float, text: string}>  $units
     * @param  array<int, array{start: float, end: float, speaker: string}>  $turns
     * @param  array<string, string>  $labels
     * @return array<int, array{speaker: string, start: float, end: float, text: string}>
     */
    protected function utterances(array $units, array $turns, array &$labels, string $fallback): array
    {
        $out = [];
        foreach ($units as $u) {
            $speaker = $fallback;
            if ($turns !== []) {
                $speaker = $this->label($this->speakerAt($u['start'], $u['end'], $turns), $labels, $fallback);
            }

            $last = $out === [] ? null : count($out) - 1;
            if ($last !== null && $out[$last]['speaker'] === $speaker && $u['start'] - $out[$last]['end'] < 2.0) {
                $out[$last]['text'] .= ' ' . $u['text'];
                $out[$last]['end'] = $u['end'];
            } else {
                $out[] = ['speaker' => $speaker, 'start' => $u['start'], 'end' => $u['end'], 'text' => $u['text']];
            }
        }

        return $out;
    }

    /** Server-Sprecher (SPEAKER_00 …) → A, B, C … — fortlaufend ab $from, über Kanäle hinweg eindeutig. */
    protected function label(string $raw, array &$labels, string $from): string
    {
        if (!isset($labels['d:' . $raw])) {
            $used = array_values($labels);
            $next = $from;
            while (in_array($next, $used, true)) {
                $next = chr(ord($next) + 1);
            }
            $labels['d:' . $raw] = $next;
        }

        return $labels['d:' . $raw];
    }

    /** Sprecher mit der größten Überlappung, sonst der zeitlich nächste. */
    protected function speakerAt(float $start, float $end, array $turns): string
    {
        $best = null;
        $bestOverlap = 0.0;
        $nearest = null;
        $nearestDist = INF;
        $mid = ($start + $end) / 2;
        foreach ($turns as $t) {
            $overlap = min($end, $t['end']) - max($start, $t['start']);
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $t['speaker'];
            }
            $dist = $mid < $t['start'] ? $t['start'] - $mid : ($mid > $t['end'] ? $mid - $t['end'] : 0.0);
            if ($dist < $nearestDist) {
                $nearestDist = $dist;
                $nearest = $t['speaker'];
            }
        }

        return $best ?? $nearest ?? 'unknown';
    }

    protected function result(array $segments, ?string $language, ?float $duration, ?string $text): array
    {
        $speakers = array_unique(array_column($segments, 'speaker'));
        $model = 'local:' . config('whisper.transcription.stt_model', 'whisper-1') . ($this->diarizes() ? '+pyannote' : '');

        return [
            'transcript' => $text !== null && $text !== '' ? $text : implode("\n\n", array_column($segments, 'text')),
            'language' => $language,
            'duration' => $duration,
            'segments' => $segments,
            'speakers_count' => count($speakers),
            'model' => $model,
            'provider_id' => null,
        ];
    }

    protected function isNoise(string $text): bool
    {
        $norm = mb_strtolower(trim($text, " .!?…\t\n"));
        if (mb_strlen($norm) < 2) {
            return true;
        }
        foreach (self::NOISE as $n) {
            if (str_contains($norm, $n)) {
                return true;
            }
        }

        return false;
    }

    protected function language(mixed $value): ?string
    {
        $v = mb_strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }

        return self::LANGUAGES[$v] ?? (strlen($v) <= 5 ? $v : null);
    }

    protected function sttUrl(): string
    {
        return rtrim((string) config('whisper.transcription.stt_url', ''), '/');
    }

    protected function client(string $apiKey): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout((int) config('whisper.transcription.timeout', 1800))->acceptJson();

        return $apiKey !== '' ? $client->withToken($apiKey) : $client;
    }
}
