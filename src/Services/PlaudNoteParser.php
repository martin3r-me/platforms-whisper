<?php

namespace Platform\Whisper\Services;

class PlaudNoteParser
{
    /**
     * Parse a Plaud note markdown into structured sections.
     */
    public function parse(string $markdown): array
    {
        return [
            'summary' => $this->extractSection($markdown, 'Zusammenfassung'),
            'action_items' => $this->extractSection($markdown, 'Nächste Vereinbarungen'),
            'ai_suggestions' => $this->extractSection($markdown, 'KI-Vorschläge'),
            'outline' => $this->extractOutline($markdown),
        ];
    }

    /**
     * Extract content between ## SectionName and the next ## heading (or end of string).
     */
    protected function extractSection(string $markdown, string $sectionName): ?string
    {
        $pattern = '/## ' . preg_quote($sectionName, '/') . '\s*\n(.*?)(?=\n## |\z)/s';

        if (preg_match($pattern, $markdown, $matches)) {
            $content = trim($matches[1]);
            return $content !== '' ? $content : null;
        }

        return null;
    }

    /**
     * Extract "Besprechungsinformationen" section as a key-value array.
     * Lines formatted as "> Key: Value" or "Key: Value".
     */
    protected function extractOutline(string $markdown): ?array
    {
        $raw = $this->extractSection($markdown, 'Besprechungsinformationen');

        if ($raw === null) {
            return null;
        }

        $outline = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            $line = trim($line);

            // Strip leading "> " blockquote markers
            if (str_starts_with($line, '> ')) {
                $line = substr($line, 2);
            }

            // Skip empty lines
            if ($line === '') {
                continue;
            }

            // Parse "Key: Value" pairs
            if (preg_match('/^(.+?):\s*(.+)$/', $line, $matches)) {
                $outline[trim($matches[1])] = trim($matches[2]);
            }
        }

        return !empty($outline) ? $outline : null;
    }
}
