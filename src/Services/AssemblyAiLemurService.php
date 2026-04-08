<?php

namespace Platform\Whisper\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AssemblyAI LeMUR - LLM-Layer direkt ueber einem Transkript.
 *
 * Wir nutzen drei Features:
 *  - generateInsights($transcriptId, $language): Titel + Summary + Action Items
 *    in einem einzigen /lemur/v3/generate/task-Call (spart Tokens + Latenz).
 *  - askQuestion($transcriptId, $question, $context): Single-Question-Q&A via
 *    /lemur/v3/generate/question-answer (nutzt Claude unter der Haube).
 *
 * LeMUR arbeitet auf der AssemblyAI-seitigen Transcript-ID (unser provider_id).
 */
class AssemblyAiLemurService
{
    private string $baseUrl = 'https://api.assemblyai.com/lemur/v3';

    /**
     * @return array{title: ?string, summary: ?string, action_items: ?string}
     */
    public function generateInsights(string $transcriptId, string $language = 'de'): array
    {
        if ($transcriptId === '') {
            return ['title' => null, 'summary' => null, 'action_items' => null];
        }

        $langName = $language === 'en' ? 'English' : 'Deutsch';

        $prompt = "Analysiere das Transkript dieses Meetings / Sprachmemos und liefere ausschliesslich ein JSON-Objekt "
            . "mit den Feldern:\n"
            . "- \"title\" (string, max 70 Zeichen, praegnant, ohne Anfuehrungszeichen)\n"
            . "- \"summary\" (string, 3-6 kurze Bullet-Points in Markdown, jede Zeile beginnt mit \"- \")\n"
            . "- \"action_items\" (string, Markdown-Liste konkreter To-dos mit \"- \", inkl. Owner wenn erkennbar; "
            . "leerer String wenn keine klaren Action Items vorhanden sind)\n\n"
            . "Sprache der Antwort: {$langName}. Kein einleitender Text, keine Markdown-Fences, nur das JSON.";

        try {
            $data = $this->runTask($transcriptId, $prompt);
            if ($data === null) {
                return ['title' => null, 'summary' => null, 'action_items' => null];
            }

            $parsed = $this->parseJson($data);

            return [
                'title' => $this->cleanTitle($parsed['title'] ?? null),
                'summary' => $this->cleanMarkdownList($parsed['summary'] ?? null),
                'action_items' => $this->cleanMarkdownList($parsed['action_items'] ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('LeMUR generateInsights failed', [
                'transcript_id' => $transcriptId,
                'error' => $e->getMessage(),
            ]);
            return ['title' => null, 'summary' => null, 'action_items' => null];
        }
    }

    /**
     * Stellt eine Frage an das Transkript. Gibt Antworttext zurueck (oder null bei Fehler).
     */
    public function askQuestion(
        string $transcriptId,
        string $question,
        ?string $context = null,
        string $language = 'de'
    ): ?string {
        $question = trim($question);
        if ($transcriptId === '' || $question === '') {
            return null;
        }

        $apiKey = $this->getApiKey();
        $timeout = (int) config('whisper.lemur.request_timeout', 120);
        $finalModel = (string) config('whisper.lemur.final_model', 'default');

        $langName = $language === 'en' ? 'English' : 'Deutsch';
        $langHint = "Antworte in {$langName}. Wenn die Antwort nicht im Transkript steht, sag das ehrlich.";

        $body = [
            'transcript_ids' => [$transcriptId],
            'final_model' => $finalModel,
            'context' => $context ? $context . "\n\n" . $langHint : $langHint,
            'questions' => [[
                'question' => $question,
                'answer_format' => 'text',
            ]],
        ];

        try {
            $response = Http::withHeaders(['authorization' => $apiKey])
                ->timeout($timeout)
                ->post($this->baseUrl . '/generate/question-answer', $body);

            if (!$response->successful()) {
                Log::warning('LeMUR Q&A failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $answers = $data['response'] ?? [];
            if (!is_array($answers) || $answers === []) {
                return null;
            }

            $first = $answers[0] ?? [];
            $answer = $first['answer'] ?? null;
            return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
        } catch (\Throwable $e) {
            Log::warning('LeMUR Q&A exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fuehrt einen freien Task gegen /lemur/v3/generate/task aus.
     */
    private function runTask(string $transcriptId, string $prompt): ?string
    {
        $apiKey = $this->getApiKey();
        $timeout = (int) config('whisper.lemur.request_timeout', 120);
        $finalModel = (string) config('whisper.lemur.final_model', 'default');
        $maxOutputSize = (int) config('whisper.lemur.max_output_size', 2000);
        $temperature = (float) config('whisper.lemur.temperature', 0.0);

        $body = [
            'transcript_ids' => [$transcriptId],
            'prompt' => $prompt,
            'final_model' => $finalModel,
            'max_output_size' => $maxOutputSize,
            'temperature' => $temperature,
        ];

        $response = Http::withHeaders(['authorization' => $apiKey])
            ->timeout($timeout)
            ->post($this->baseUrl . '/generate/task', $body);

        if (!$response->successful()) {
            Log::warning('LeMUR task failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $text = $data['response'] ?? null;
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    private function parseJson(string $content): array
    {
        $content = trim($content);
        // Markdown-Fence entfernen falls vorhanden
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: erstes JSON-Objekt im Text finden
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function cleanTitle(mixed $title): ?string
    {
        if (!is_string($title)) {
            return null;
        }
        $title = trim($title, " \t\n\r\0\x0B\"'");
        if ($title === '') {
            return null;
        }
        if (mb_strlen($title) > 100) {
            $title = mb_substr($title, 0, 97) . '…';
        }
        return $title;
    }

    private function cleanMarkdownList(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode("\n", array_map(
                fn($s) => is_string($s)
                    ? (str_starts_with(trim($s), '-') ? $s : '- ' . trim($s))
                    : '',
                $value
            ));
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
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
