<?php

namespace Platform\Whisper\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AssemblyAI-Transkription mit Speaker Diarization.
 *
 * Flow:
 *   1) POST /v2/upload   (raw audio body)        → upload_url
 *   2) POST /v2/transcript { audio_url, ... }    → transcript_id (status queued|processing)
 *   3) Polling GET /v2/transcript/{id}           → status completed|error
 *
 * Liefert Transkript + Speaker-Segmente in einem einheitlichen Format:
 *   [
 *     ['speaker' => 'A', 'start' => 0.0, 'end' => 3.2, 'text' => '...'],
 *     ...
 *   ]
 */
class AssemblyAiTranscriptionService
{
    private string $baseUrl = 'https://api.assemblyai.com/v2';

    /**
     * @param  string   $filePath   Absoluter Pfad zur Audio-Datei
     * @param  string   $filename   Nur fuer Logging / Fallback
     * @param  ?string  $language   Sprach-Code (ISO), null = auto-detect
     * @param  bool     $diarize    Speaker Diarization aktivieren
     * @return array{
     *     transcript: string,
     *     language: ?string,
     *     duration: ?float,
     *     segments: array<int, array{speaker: string, start: float, end: float, text: string}>,
     *     speakers_count: int,
     *     model: string,
     *     provider_id: string,
     * }
     */
    public function transcribe(
        string $filePath,
        string $filename = 'audio.webm',
        ?string $language = 'de',
        bool $diarize = true
    ): array {
        if (!is_file($filePath)) {
            throw new RuntimeException("Audio-Datei nicht gefunden: {$filePath}");
        }

        $apiKey = $this->getApiKey();
        $timeout = (int) config('whisper.assemblyai.request_timeout', 120);
        $pollInterval = (int) config('whisper.assemblyai.poll_interval_seconds', 3);
        $maxWaitSeconds = (int) config('whisper.assemblyai.max_wait_seconds', 1500);

        // 1) Upload
        $uploadUrl = $this->uploadFile($apiKey, $filePath, $timeout);

        // 2) Submit transcription job
        $speechModels = (array) config('whisper.assemblyai.speech_models', ['universal-3-pro', 'universal-2']);
        // Defensive: leere/falsche Config → sicherer Default.
        $speechModels = array_values(array_filter($speechModels, fn($m) => is_string($m) && $m !== ''));
        if ($speechModels === []) {
            $speechModels = ['universal-3-pro', 'universal-2'];
        }

        $payload = [
            'audio_url' => $uploadUrl,
            'speech_models' => $speechModels,
            'speaker_labels' => $diarize,
            'punctuate' => true,
            'format_text' => true,
        ];

        if ($language && $language !== 'auto') {
            $payload['language_code'] = $language;
        } else {
            $payload['language_detection'] = true;
        }

        $expectedSpeakers = (int) config('whisper.assemblyai.speakers_expected', 0);
        if ($diarize && $expectedSpeakers > 0) {
            $payload['speakers_expected'] = $expectedSpeakers;
        }

        $submit = Http::withHeaders(['authorization' => $apiKey])
            ->timeout($timeout)
            ->post($this->baseUrl . '/transcript', $payload);

        if (!$submit->successful()) {
            throw new RuntimeException(
                'AssemblyAI submit failed: ' . $submit->status() . ' - ' . $submit->body()
            );
        }

        $submitData = $submit->json();
        $transcriptId = $submitData['id'] ?? null;
        if (!$transcriptId) {
            throw new RuntimeException('AssemblyAI submit: fehlende transcript id.');
        }

        // 3) Poll
        $data = $this->pollUntilDone($apiKey, $transcriptId, $pollInterval, $maxWaitSeconds, $timeout);

        if (($data['status'] ?? null) === 'error') {
            throw new RuntimeException('AssemblyAI transcription error: ' . ($data['error'] ?? 'unknown'));
        }

        return $this->mapResponse($data);
    }

    private function uploadFile(string $apiKey, string $filePath, int $timeout): string
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Kann Audio-Datei nicht oeffnen: ' . $filePath);
        }

        try {
            $response = Http::withHeaders([
                'authorization' => $apiKey,
                'transfer-encoding' => 'chunked',
                'content-type' => 'application/octet-stream',
            ])
                ->timeout($timeout)
                ->withBody($handle, 'application/octet-stream')
                ->post($this->baseUrl . '/upload');
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'AssemblyAI upload failed: ' . $response->status() . ' - ' . $response->body()
            );
        }

        $url = $response->json('upload_url');
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('AssemblyAI upload: upload_url fehlt in Antwort.');
        }
        return $url;
    }

    private function pollUntilDone(
        string $apiKey,
        string $transcriptId,
        int $pollInterval,
        int $maxWaitSeconds,
        int $timeout
    ): array {
        $endpoint = $this->baseUrl . '/transcript/' . $transcriptId;
        $start = time();

        while (true) {
            $res = Http::withHeaders(['authorization' => $apiKey])
                ->timeout($timeout)
                ->get($endpoint);

            if (!$res->successful()) {
                throw new RuntimeException(
                    'AssemblyAI poll failed: ' . $res->status() . ' - ' . $res->body()
                );
            }

            $data = $res->json();
            $status = $data['status'] ?? 'unknown';

            if ($status === 'completed' || $status === 'error') {
                return $data;
            }

            if ((time() - $start) > $maxWaitSeconds) {
                throw new RuntimeException(
                    "AssemblyAI polling timeout nach {$maxWaitSeconds}s (Status: {$status}, id: {$transcriptId})."
                );
            }

            sleep(max(1, $pollInterval));
        }
    }

    /**
     * Normalisiert AssemblyAI-Response in unser internes Format.
     */
    private function mapResponse(array $data): array
    {
        $text = (string) ($data['text'] ?? '');

        $segments = [];
        $speakerMap = [];

        // Utterances existieren nur bei speaker_labels=true
        $utterances = $data['utterances'] ?? null;
        if (is_array($utterances)) {
            foreach ($utterances as $u) {
                $speakerRaw = (string) ($u['speaker'] ?? 'A');
                if (!isset($speakerMap[$speakerRaw])) {
                    $speakerMap[$speakerRaw] = $speakerRaw;
                }

                $segments[] = [
                    'speaker' => $speakerMap[$speakerRaw],
                    'start' => isset($u['start']) ? ((float) $u['start']) / 1000.0 : 0.0,
                    'end' => isset($u['end']) ? ((float) $u['end']) / 1000.0 : 0.0,
                    'text' => trim((string) ($u['text'] ?? '')),
                ];
            }
        }

        // Dauer: AssemblyAI liefert audio_duration (seconds)
        $duration = null;
        if (isset($data['audio_duration'])) {
            $duration = (float) $data['audio_duration'];
        }

        $language = $data['language_code'] ?? null;

        return [
            'transcript' => trim($text),
            'language' => is_string($language) ? $language : null,
            'duration' => $duration,
            'segments' => $segments,
            'speakers_count' => count($speakerMap),
            'model' => 'assemblyai:' . ($data['speech_model'] ?? ($data['speech_models'][0] ?? 'universal')),
            'provider_id' => (string) ($data['id'] ?? ''),
        ];
    }

    private function getApiKey(): string
    {
        $key = config('whisper.assemblyai.api_key');
        if (!is_string($key) || $key === '') {
            $key = env('ASSEMBLYAI_API_KEY') ?: '';
        }
        if ($key === '') {
            throw new RuntimeException('AUTHENTICATION_FAILED: ASSEMBLYAI_API_KEY fehlt oder ist leer.');
        }
        return $key;
    }
}
