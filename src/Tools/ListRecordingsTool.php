<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class ListRecordingsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recordings.GET';
    }

    public function getDescription(): string
    {
        return 'GET /whisper/recordings - Listet Whisper-Aufnahmen. Parameter: team_id (optional), status (optional: pending, processing, completed, failed), search/sort/limit/offset (optional).';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema(),
            [
                'properties' => [
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['pending', 'processing', 'completed', 'failed'],
                        'description' => 'Optional: Filter nach Status.',
                    ],
                ],
            ]
        );
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            $query = WhisperRecording::query()->where('team_id', $teamId);

            if (isset($arguments['status'])) {
                $query->where('status', $arguments['status']);
            }

            $this->applyStandardFilters($query, $arguments, [
                'title', 'language', 'status', 'duration_seconds', 'created_at', 'updated_at',
            ]);
            $this->applyStandardSearch($query, $arguments, ['title', 'transcript']);
            $this->applyStandardSort($query, $arguments, [
                'title', 'duration_seconds', 'status', 'created_at', 'updated_at',
            ], 'created_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(function (WhisperRecording $rec) {
                return [
                    'id' => $rec->id,
                    'uuid' => $rec->uuid,
                    'title' => $rec->title,
                    'language' => $rec->language,
                    'duration_seconds' => $rec->duration_seconds,
                    'status' => $rec->status,
                    'speakers_count' => $rec->speakers_count,
                    'file_size_bytes' => $rec->file_size_bytes,
                    'transcript_length' => mb_strlen((string) $rec->transcript),
                    'team_id' => $rec->team_id,
                    'created_by_user_id' => $rec->created_by_user_id,
                    'created_at' => $rec->created_at?->toISOString(),
                    'updated_at' => $rec->updated_at?->toISOString(),
                ];
            })->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Aufnahmen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['whisper', 'recordings', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
