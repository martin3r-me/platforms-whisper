<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class LinkRecordingToEntityTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recording.link.POST';
    }

    public function getDescription(): string
    {
        return 'POST /whisper/recording/link - Verknuepft eine Aufnahme mit einer Organization-Entity (z.B. Projekt, Kunde, Abteilung). ERFORDERLICH: recording_id, entity_id. Eine Aufnahme kann nur EINMAL gelinkt werden - wiederholtes Verknuepfen aktualisiert die Verbindung.';
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
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Organization-Entity (ERFORDERLICH). Nutze "organization.entities.GET" um Entities zu finden.',
                ],
            ],
            'required' => ['recording_id', 'entity_id'],
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
            $entityId = (int) ($arguments['entity_id'] ?? 0);

            if ($recordingId <= 0 || $entityId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'recording_id und entity_id sind erforderlich.');
            }

            $recording = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->find($recordingId);
            if (!$recording) {
                return ToolResult::error('NOT_FOUND', 'Aufnahme nicht gefunden (oder kein Zugriff).');
            }

            $entity = OrganizationEntity::query()
                ->where('team_id', $teamId)
                ->find($entityId);
            if (!$entity) {
                return ToolResult::error('NOT_FOUND', 'Organization-Entity nicht gefunden (oder kein Zugriff).');
            }

            $context = $recording->attachOrganizationContext($entity);

            return ToolResult::success([
                'recording_id' => $recording->id,
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'context_id' => $context->id,
                'message' => "Aufnahme '{$recording->title}' mit Entity '{$entity->name}' verknuepft.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Verknuepfen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'recording', 'organization', 'link'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
