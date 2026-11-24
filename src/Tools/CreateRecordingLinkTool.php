<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Tools\Concerns\HandlesRecordingLinks;

class CreateRecordingLinkTool implements ToolContract, ToolMetadataContract
{
    use HandlesRecordingLinks;

    public function getName(): string
    {
        return 'whisper.recording.links.POST';
    }

    public function getDescription(): string
    {
        return 'POST /whisper/recording/links - Hängt eine Aufnahme lose an ein Objekt (Morph-Alias + ID), z. B. gathering_meeting. '
            . 'Pflicht: recording_id, linkable_type, linkable_id. Optional: role (z. B. protocol). Idempotent.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID. Default: Kontext.'],
                'recording_id' => ['type' => 'integer'],
                'linkable_type' => ['type' => 'string', 'description' => 'Morph-Alias des Objekts, z. B. gathering_meeting.'],
                'linkable_id' => ['type' => 'integer'],
                'role' => ['type' => 'string'],
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
        try {
            $link = $this->links()->link($recording, (string) ($arguments['linkable_type'] ?? ''), (int) ($arguments['linkable_id'] ?? 0), $arguments['role'] ?? null, $context->user);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'link' => $this->linkToArray($link),
            'message' => "Aufnahme #{$recording->id} hängt an {$link->linkable_type} #{$link->linkable_id}.",
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
