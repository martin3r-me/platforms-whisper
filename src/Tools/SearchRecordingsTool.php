<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class SearchRecordingsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recordings.search.GET';
    }

    public function getDescription(): string
    {
        return 'GET /whisper/recordings/search - Volltext-Suche in Titeln und Transkripten. ERFORDERLICH: query. Optional: team_id, limit (default 20).';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'Suchbegriff (ERFORDERLICH).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional: max. Anzahl Ergebnisse (default 20, max 100).',
                ],
            ],
            'required' => ['query'],
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

            $query = trim((string) ($arguments['query'] ?? ''));
            if ($query === '') {
                return ToolResult::error('VALIDATION_ERROR', 'query ist erforderlich.');
            }

            $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));

            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

            $results = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->where(function ($q) use ($like) {
                    $q->where('title', 'like', $like)
                      ->orWhere('transcript', 'like', $like);
                })
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $data = $results->map(function (WhisperRecording $rec) use ($query) {
                $snippet = null;
                if ($rec->transcript) {
                    $pos = mb_stripos($rec->transcript, $query);
                    if ($pos !== false) {
                        $start = max(0, $pos - 60);
                        $snippet = ($start > 0 ? '…' : '') . mb_substr($rec->transcript, $start, 200) . '…';
                    }
                }
                return [
                    'id' => $rec->id,
                    'uuid' => $rec->uuid,
                    'title' => $rec->title,
                    'language' => $rec->language,
                    'duration_seconds' => $rec->duration_seconds,
                    'status' => $rec->status,
                    'snippet' => $snippet,
                    'created_at' => $rec->created_at?->toISOString(),
                ];
            })->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'query' => $query,
                'count' => count($data),
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei der Suche: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['whisper', 'recordings', 'search'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
