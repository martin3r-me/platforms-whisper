<?php

namespace Platform\Whisper\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhisperTranscriptionService
{
    private string $endpoint = 'https://api.openai.com/v1/audio/transcriptions';

    private function getApiKey(): string
    {
        $key = config('services.openai.api_key');
        if (!is_string($key) || $key === '') {
            $key = config('services.openai.key') ?? '';
        }
        if ($key === '') {
            $key = env('OPENAI_API_KEY') ?? '';
        }
        if ($key === '') {
            throw new RuntimeException('AUTHENTICATION_FAILED: OPENAI_API_KEY fehlt oder ist leer.');
        }
        return $key;
    }

    /**
     * Transkribiert eine Audio-Datei via OpenAI Whisper API.
     *
     * @return array{transcript: string, language: ?string, duration: ?float, model: string}
     */
    public function transcribe(string $filePath, string $filename = 'audio.webm', ?string $language = 'de', string $model = 'whisper-1'): array
    {
        $apiKey = $this->getApiKey();

        $request = Http::withToken($apiKey)
            ->timeout(120)
            ->attach('file', file_get_contents($filePath), $filename);

        $payload = [
            'model' => $model,
            'response_format' => 'verbose_json',
        ];

        if ($language) {
            $payload['language'] = $language;
        }

        $response = $request->post($this->endpoint, $payload);

        if (!$response->successful()) {
            $body = $response->body();
            throw new RuntimeException('Whisper API Fehler: ' . $response->status() . ' - ' . $body);
        }

        $data = $response->json();

        return [
            'transcript' => $data['text'] ?? '',
            'language' => $data['language'] ?? $language,
            'duration' => isset($data['duration']) ? (float) $data['duration'] : null,
            'model' => $model,
        ];
    }
}
