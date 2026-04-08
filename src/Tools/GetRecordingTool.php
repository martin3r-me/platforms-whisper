<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class GetRecordingTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recording.GET';
    }

    public function getDescription(): string
    {
        return 'GET /whisper/recording - Liefert eine einzelne Aufnahme inkl. Metadaten und Transkript. ERFORDERLICH: recording_id. Optional: team_id.';
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
                'recording_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Aufnahme (ERFORDERLICH).',
                ],
            ],
            'required' => ['recording_id'],
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

            $recordingId = (int) ($arguments['recording_id'] ?? 0);
            if ($recordingId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'recording_id ist erforderlich.');
            }

            $rec = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->find($recordingId);

            if (!$rec) {
                return ToolResult::error('NOT_FOUND', 'Aufnahme nicht gefunden (oder kein Zugriff).');
            }

            $entity = $rec->getOrganizationEntity();

            return ToolResult::success([
                'id' => $rec->id,
                'uuid' => $rec->uuid,
                'title' => $rec->title,
                'transcript' => $rec->transcript,
                'summary' => $rec->summary,
                'segments' => $rec->segments,
                'speakers_count' => $rec->speakers_count,
                'language' => $rec->language,
                'duration_seconds' => $rec->duration_seconds,
                'model' => $rec->model,
                'status' => $rec->status,
                'error_message' => $rec->error_message,
                'file_size_bytes' => $rec->file_size_bytes,
                'team_id' => $rec->team_id,
                'created_by_user_id' => $rec->created_by_user_id,
                'organization_entity' => $entity ? [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'type' => $entity->type?->name,
                ] : null,
                'created_at' => $rec->created_at?->toISOString(),
                'updated_at' => $rec->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Aufnahme: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['whisper', 'recording', 'get'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
