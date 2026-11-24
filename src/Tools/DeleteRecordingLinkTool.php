<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Tools\Concerns\HandlesRecordingLinks;

class DeleteRecordingLinkTool implements ToolContract, ToolMetadataContract
{
    use HandlesRecordingLinks;

    public function getName(): string
    {
        return 'whisper.recording.links.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /whisper/recording/links - Löst eine Aufnahme von einem Objekt. Pflicht: recording_id, linkable_type, linkable_id. Die Aufnahme selbst bleibt.';
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
            'required' => ['recording_id', 'linkable_type', 'linkable_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        ['recording' => $recording, 'error' => $error] = $this->findRecording($arguments, $context);
        if ($error) {
            return $error;
        }
        $removed = $this->links()->unlink($recording, (string) ($arguments['linkable_type'] ?? ''), (int) ($arguments['linkable_id'] ?? 0));

        return ToolResult::success([
            'removed' => $removed,
            'message' => $removed ? 'Verknüpfung gelöst.' : 'Es gab keine solche Verknüpfung.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'recordings', 'links'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
