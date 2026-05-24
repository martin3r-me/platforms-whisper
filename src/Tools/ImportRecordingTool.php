<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class ImportRecordingTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recordings.import.POST';
    }

    public function getDescription(): string
    {
        return 'POST /whisper/recordings/import - Importiert eine externe Aufnahme (z.B. von Plaud) mit Transkript, Segmenten, Summary und Action Items. Duplikat-Erkennung via source + source_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'Quelle der Aufnahme (z.B. "plaud"). Wird als Prefix im model-Feld gespeichert.',
                ],
                'source_id' => [
                    'type' => 'string',
                    'description' => 'Externe ID der Aufnahme (z.B. Plaud file_id). Wird in provider_id gespeichert. Verhindert Duplikate.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Titel der Aufnahme (ERFORDERLICH).',
                ],
                'transcript' => [
                    'type' => 'string',
                    'description' => 'Vollstaendiges Transkript als Fliesstext.',
                ],
                'segments' => [
                    'type' => 'array',
                    'description' => 'Speaker-Segmente. Array von Objekten mit: speaker (string), start (float, Sekunden), end (float, Sekunden), text (string).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'speaker' => ['type' => 'string'],
                            'start' => ['type' => 'number'],
                            'end' => ['type' => 'number'],
                            'text' => ['type' => 'string'],
                        ],
                    ],
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional: Zusammenfassung (Markdown).',
                ],
                'action_items' => [
                    'type' => 'string',
                    'description' => 'Optional: Action Items (Markdown).',
                ],
                'duration_seconds' => [
                    'type' => 'integer',
                    'description' => 'Optional: Dauer in Sekunden.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional: ISO-Sprachcode (z.B. "de", "en"). Default: "de".',
                ],
                'speaker_map' => [
                    'type' => 'object',
                    'description' => 'Optional: Speaker-Zuordnung, z.B. {"A": "Martin", "B": "Gregor"}.',
                ],
                'recorded_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Zeitpunkt der Aufnahme (ISO 8601). Wird als created_at gesetzt.',
                ],
            ],
            'required' => ['title', 'source', 'source_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            $source = trim((string) ($arguments['source'] ?? ''));
            $sourceId = trim((string) ($arguments['source_id'] ?? ''));
            $title = trim((string) ($arguments['title'] ?? ''));

            if ($source === '' || $sourceId === '') {
                return ToolResult::error('VALIDATION_ERROR', 'source und source_id sind erforderlich.');
            }
            if ($title === '') {
                return ToolResult::error('VALIDATION_ERROR', 'title ist erforderlich.');
            }

            $providerId = $source . ':' . $sourceId;

            // Duplikat-Check
            $existing = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->where('provider_id', $providerId)
                ->first();

            if ($existing) {
                return ToolResult::success([
                    'id' => $existing->id,
                    'uuid' => $existing->uuid,
                    'title' => $existing->title,
                    'status' => $existing->status,
                    'team_id' => $existing->team_id,
                    'duplicate' => true,
                    'message' => "Aufnahme existiert bereits (ID #{$existing->id}). Kein erneuter Import.",
                ]);
            }

            // Segmente normalisieren
            $segments = $arguments['segments'] ?? [];
            $speakerLabels = [];
            foreach ($segments as $seg) {
                $speaker = $seg['speaker'] ?? 'A';
                $speakerLabels[$speaker] = true;
            }
            $speakersCount = count($speakerLabels);

            // Transcript aus Segmenten bauen, falls nicht mitgegeben
            $transcript = trim((string) ($arguments['transcript'] ?? ''));
            if ($transcript === '' && !empty($segments)) {
                $transcript = implode("\n\n", array_map(
                    fn ($seg) => ($seg['speaker'] ?? 'A') . ': ' . ($seg['text'] ?? ''),
                    $segments
                ));
            }

            // Speaker-Map: aus "Speaker 1" → "A", "Speaker 2" → "B" normalisieren
            $speakerMap = $arguments['speaker_map'] ?? null;

            $recording = WhisperRecording::create([
                'team_id' => $teamId,
                'created_by_user_id' => $context->user?->id,
                'title' => $title,
                'transcript' => $transcript ?: null,
                'summary' => isset($arguments['summary']) ? trim($arguments['summary']) : null,
                'action_items' => isset($arguments['action_items']) ? trim($arguments['action_items']) : null,
                'segments' => !empty($segments) ? $segments : null,
                'speakers_count' => $speakersCount > 0 ? $speakersCount : null,
                'speaker_map' => $speakerMap,
                'language' => $arguments['language'] ?? 'de',
                'duration_seconds' => isset($arguments['duration_seconds']) ? (int) $arguments['duration_seconds'] : null,
                'model' => 'import:' . $source,
                'provider_id' => $providerId,
                'status' => WhisperRecording::STATUS_COMPLETED,
            ]);

            // Set created_at to recorded_at if provided
            if (!empty($arguments['recorded_at'])) {
                try {
                    $recording->created_at = \Carbon\Carbon::parse($arguments['recorded_at']);
                    $recording->saveQuietly();
                } catch (\Throwable) {
                    // Ignore invalid date
                }
            }

            return ToolResult::success([
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'title' => $recording->title,
                'status' => $recording->status,
                'speakers_count' => $recording->speakers_count,
                'duration_seconds' => $recording->duration_seconds,
                'team_id' => $recording->team_id,
                'duplicate' => false,
                'message' => "Aufnahme erfolgreich importiert als #{$recording->id}.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Import: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'recordings', 'import', 'plaud'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
