<?php

namespace Platform\Whisper\Services;

use Illuminate\Support\Facades\Log;
use Platform\Core\Services\OpenAiService;

class WhisperSummaryService
{
    public function __construct(private OpenAiService $openAi)
    {
    }

    /**
     * Erzeugt Titel + Zusammenfassung aus einem Transkript.
     *
     * @return array{title: ?string, summary: ?string}
     */
    public function summarize(string $transcript, ?string $language = 'de'): array
    {
        $clean = trim($transcript);
        if ($clean === '') {
            return ['title' => null, 'summary' => null];
        }

        // Sehr lange Transkripte kürzen (Whisper kann Stunden liefern).
        // Für Titel+Summary reichen die ersten ~12k Zeichen in der Regel.
        if (mb_strlen($clean) > 12000) {
            $clean = mb_substr($clean, 0, 12000) . "\n\n[...gekuerzt...]";
        }

        $langHint = $language === 'en' ? 'English' : 'Deutsch';

        $system = "Du bist ein Assistent, der Meeting-/Sprachmemo-Transkripte zusammenfasst. "
            . "Antworte ausschliesslich als kompaktes JSON-Objekt mit den Feldern "
            . "\"title\" (max 70 Zeichen, praegnant, ohne Anfuehrungszeichen) und "
            . "\"summary\" (3-6 kurze Bullet-Points in Markdown, jeweils mit '- ' beginnend). "
            . "Sprache der Antwort: {$langHint}. Kein einleitender Text, kein Markdown-Fence, nur das JSON.";

        $user = "Transkript:\n\n" . $clean;

        try {
            $response = $this->openAi->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                OpenAiService::DEFAULT_MODEL,
                [
                    'tools' => false,
                    'max_tokens' => 600,
                ]
            );

            $content = $this->extractContent($response);
            if (!$content) {
                return ['title' => null, 'summary' => null];
            }

            $parsed = $this->parseJson($content);

            return [
                'title' => $this->cleanTitle($parsed['title'] ?? null),
                'summary' => $this->cleanSummary($parsed['summary'] ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('Whisper summary failed', [
                'error' => $e->getMessage(),
            ]);
            return ['title' => null, 'summary' => null];
        }
    }

    private function extractContent(array $response): ?string
    {
        // OpenAiService::chat() liefert Responses-API Output. Versuche
        // mehrere Shapes robust auszulesen.
        if (isset($response['content']) && is_string($response['content'])) {
            return $response['content'];
        }
        if (isset($response['message']['content']) && is_string($response['message']['content'])) {
            return $response['message']['content'];
        }
        if (isset($response['output']) && is_array($response['output'])) {
            foreach ($response['output'] as $item) {
                if (($item['type'] ?? null) === 'message' && isset($item['content'])) {
                    foreach ((array) $item['content'] as $c) {
                        if (isset($c['text']) && is_string($c['text'])) {
                            return $c['text'];
                        }
                    }
                }
            }
        }
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        return null;
    }

    private function parseJson(string $content): array
    {
        $content = trim($content);
        // Fence entfernen, falls vorhanden
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: JSON-Block im Text finden
        if (preg_match('/\{.*\}/s', $content, $m)) {
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

    private function cleanSummary(mixed $summary): ?string
    {
        if (is_array($summary)) {
            // Falls LLM ein Array liefert
            $summary = implode("\n", array_map(
                fn($s) => is_string($s) ? (str_starts_with(trim($s), '-') ? $s : '- ' . trim($s)) : '',
                $summary
            ));
        }
        if (!is_string($summary)) {
            return null;
        }
        $summary = trim($summary);
        return $summary !== '' ? $summary : null;
    }
}
