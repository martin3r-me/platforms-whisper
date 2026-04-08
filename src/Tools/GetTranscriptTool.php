<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class GetTranscriptTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recording.transcript.GET';
    }

    public function getDescription(): string
    {
        return 'GET /whisper/recording/transcript - Liefert ausschliesslich das reine Transkript einer Aufnahme (ohne Metadaten). Praktisch fuer LLM-Verarbeitung. ERFORDERLICH: recording_id.';
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

            if ($rec->status !== WhisperRecording::STATUS_COMPLETED) {
                return ToolResult::error('NOT_READY', "Transkript noch nicht verfuegbar (Status: {$rec->status}).");
            }

            return ToolResult::success([
                'id' => $rec->id,
                'title' => $rec->title,
                'language' => $rec->language,
                'duration_seconds' => $rec->duration_seconds,
                'transcript' => $rec->transcript,
                'transcript_length' => mb_strlen((string) $rec->transcript),
                'summary' => $rec->summary,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Transkripts: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['whisper', 'transcript', 'get'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
