<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\WhisperRecordingService;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class UpdateRecordingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recordings.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /whisper/recordings - Aktualisiert eine Aufnahme. ERFORDERLICH: recording_id. Optional: title, transcript, language.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'recording_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Aufnahme (ERFORDERLICH).',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional: Neuer Titel.',
                ],
                'transcript' => [
                    'type' => 'string',
                    'description' => 'Optional: Neues Transkript (z.B. nach manueller Korrektur).',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional: ISO-Sprachcode (z.B. "de", "en").',
                ],
            ],
            'required' => ['recording_id'],
        ]);
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

            $recording = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->find($recordingId);

            if (!$recording) {
                return ToolResult::error('NOT_FOUND', 'Aufnahme nicht gefunden (oder kein Zugriff).');
            }

            $payload = [];
            foreach (['title', 'transcript', 'language'] as $field) {
                if (array_key_exists($field, $arguments)) {
                    $payload[$field] = $arguments[$field];
                }
            }

            if (empty($payload)) {
                return ToolResult::error('NO_CHANGE', 'Keine Aenderungen uebergeben.');
            }

            $service = new WhisperRecordingService();
            $recording = $service->update($recording, $payload);

            return ToolResult::success([
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'title' => $recording->title,
                'language' => $recording->language,
                'status' => $recording->status,
                'team_id' => $recording->team_id,
                'message' => "Aufnahme #{$recording->id} erfolgreich aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Aufnahme: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'recordings', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
