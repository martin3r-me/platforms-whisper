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
        return 'POST /whisper/recordings/import - Generischer Multi-Step-Import: Legt Recording mit Metadaten an (Step 1). '
            . 'Segmente danach via whisper.recordings.segments.APPEND in Batches anhaengen (Step 2). '
            . 'Letzten APPEND-Call mit is_last_batch=true finalisieren (Step 3). '
            . 'Duplikat-Erkennung via source + source_id. '
            . 'Fuer Plaud-Import empfohlen: whisper.plaud.sync.POST (ein Call statt drei Steps).';
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
                    'description' => 'Optional: Zeitpunkt der Aufnahme (ISO 8601).',
                ],
                'device_serial' => [
                    'type' => 'string',
                    'description' => 'Optional: Seriennummer des Aufnahmegeräts.',
                ],
                'source_url' => [
                    'type' => 'string',
                    'description' => 'Optional: URL zur Quell-Aufnahme.',
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

            $speakerMap = $arguments['speaker_map'] ?? null;

            $recordedAt = null;
            if (!empty($arguments['recorded_at'])) {
                try {
                    $recordedAt = \Carbon\Carbon::parse($arguments['recorded_at']);
                } catch (\Throwable) {
                    // Ignore invalid date
                }
            }

            $recording = WhisperRecording::create([
                'team_id' => $teamId,
                'created_by_user_id' => $context->user?->id,
                'title' => $title,
                'speaker_map' => $speakerMap,
                'language' => $arguments['language'] ?? 'de',
                'duration_seconds' => isset($arguments['duration_seconds']) ? (int) $arguments['duration_seconds'] : null,
                'model' => 'import:' . $source,
                'provider_id' => $providerId,
                'device_serial' => isset($arguments['device_serial']) ? trim($arguments['device_serial']) : null,
                'source_url' => isset($arguments['source_url']) ? trim($arguments['source_url']) : null,
                'recorded_at' => $recordedAt,
                'status' => WhisperRecording::STATUS_PROCESSING,
            ]);

            return ToolResult::success([
                'recording_id' => $recording->id,
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'title' => $recording->title,
                'status' => $recording->status,
                'duration_seconds' => $recording->duration_seconds,
                'team_id' => $recording->team_id,
                'duplicate' => false,
                'message' => "Aufnahme angelegt als #{$recording->id}. Nutze whisper.recordings.segments.APPEND um Segmente in Batches anzuhängen.",
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
