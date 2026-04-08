<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\WhisperRecordingService;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class DeleteRecordingTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recordings.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /whisper/recordings - Loescht eine Aufnahme inkl. Transkript. ERFORDERLICH: recording_id. Optional: team_id. Nicht reversibel!';
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

            $recording = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->find($recordingId);

            if (!$recording) {
                return ToolResult::error('NOT_FOUND', 'Aufnahme nicht gefunden (oder kein Zugriff).');
            }

            $title = $recording->title ?: ('Aufnahme #' . $recording->id);
            $service = new WhisperRecordingService();
            $service->delete($recording);

            return ToolResult::success([
                'deleted' => true,
                'id' => $recordingId,
                'message' => "Aufnahme '{$title}' erfolgreich geloescht.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Loeschen der Aufnahme: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'recordings', 'delete'],
            'risk_level' => 'destructive',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
