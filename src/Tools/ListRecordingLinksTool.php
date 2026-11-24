<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Tools\Concerns\HandlesRecordingLinks;

class ListRecordingLinksTool implements ToolContract, ToolMetadataContract
{
    use HandlesRecordingLinks;

    public function getName(): string
    {
        return 'whisper.recording.links.GET';
    }

    public function getDescription(): string
    {
        return 'GET /whisper/recording/links - Verknüpfungen von Aufnahmen mit Objekten. Entweder recording_id (woran hängt die Aufnahme?) '
            . 'oder linkable_type + linkable_id (welche Aufnahmen hängen an diesem Objekt, z. B. gathering_meeting #7). Nur Aufnahmen des Teams.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID. Default: Kontext.'],
                'recording_id' => ['type' => 'integer'],
                'linkable_type' => ['type' => 'string'],
                'linkable_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!empty($arguments['recording_id'])) {
            ['recording' => $recording, 'error' => $error] = $this->findRecording($arguments, $context);
            if ($error) {
                return $error;
            }

            return ToolResult::success([
                'recording_id' => $recording->id,
                'links' => $this->links()->linksFor($recording)->map(fn ($l) => $this->linkToArray($l))->values()->all(),
            ]);
        }

        $type = trim((string) ($arguments['linkable_type'] ?? ''));
        $id = (int) ($arguments['linkable_id'] ?? 0);
        if ($type === '' || $id <= 0) {
            return ToolResult::error('recording_id oder linkable_type + linkable_id angeben.', 'VALIDATION_ERROR');
        }
        $resolved = $this->resolveTeam($arguments, $context);
        if ($resolved['error']) {
            return $resolved['error'];
        }

        return ToolResult::success([
            'linkable_type' => $type,
            'linkable_id' => $id,
            'recordings' => $this->links()->recordingsFor($type, $id, (int) $resolved['team_id'])->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'status' => $r->status,
                'source' => $r->source,
                'recorded_at' => ($r->recorded_at ?? $r->created_at)?->toIso8601String(),
                'duration_seconds' => $r->duration_seconds,
                'speakers_count' => $r->speakers_count,
            ])->values()->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['whisper', 'recordings', 'links'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
