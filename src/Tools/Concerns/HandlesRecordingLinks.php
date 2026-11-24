<?php

namespace Platform\Whisper\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Models\WhisperRecordingLink;
use Platform\Whisper\Services\RecordingLinks;

trait HandlesRecordingLinks
{
    use ResolvesWhisperTeam;

    /** @return array{recording: ?WhisperRecording, error: ?ToolResult} */
    protected function findRecording(array $arguments, ToolContext $context): array
    {
        $resolved = $this->resolveTeam($arguments, $context);
        if ($resolved['error']) {
            return ['recording' => null, 'error' => $resolved['error']];
        }
        $id = (int) ($arguments['recording_id'] ?? 0);
        $recording = $id > 0 ? WhisperRecording::forTeam((int) $resolved['team_id'])->find($id) : null;

        return $recording
            ? ['recording' => $recording, 'error' => null]
            : ['recording' => null, 'error' => ToolResult::error('Aufnahme nicht gefunden (recording_id im aktuellen Team).', 'NOT_FOUND')];
    }

    protected function links(): RecordingLinks
    {
        return app(RecordingLinks::class);
    }

    protected function linkToArray(WhisperRecordingLink $link): array
    {
        return [
            'id' => $link->id,
            'recording_id' => $link->recording_id,
            'linkable_type' => $link->linkable_type,
            'linkable_id' => $link->linkable_id,
            'role' => $link->role,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
