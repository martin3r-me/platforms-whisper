<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class AppendSegmentsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.recordings.segments.APPEND';
    }

    public function getDescription(): string
    {
        return 'APPEND /whisper/recordings/{id}/segments - Hängt Segmente in Batches an eine bestehende Recording an. Max ~50 Segmente pro Call. Bei is_last_batch=true wird der Transcript-Text aus allen Segmenten zusammengebaut und die Recording finalisiert.';
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
                    'description' => 'ID der Recording, an die Segmente angehängt werden sollen (aus import.POST Response).',
                ],
                'segments' => [
                    'type' => 'array',
                    'description' => 'Speaker-Segmente (max ~50 pro Call). Array von Objekten mit: speaker (string), start (float, Sekunden), end (float, Sekunden), text (string).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'speaker' => ['type' => 'string'],
                            'start' => ['type' => 'number'],
                            'end' => ['type' => 'number'],
                            'text' => ['type' => 'string'],
                        ],
                    ],
                ],
                'is_last_batch' => [
                    'type' => 'boolean',
                    'description' => 'true wenn dies der letzte Batch ist. Finalisiert die Recording: baut Transcript-Text, zählt Speaker, setzt Status auf completed.',
                ],
            ],
            'required' => ['recording_id', 'segments'],
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

            $recordingId = $arguments['recording_id'] ?? null;
            if (!$recordingId) {
                return ToolResult::error('VALIDATION_ERROR', 'recording_id ist erforderlich.');
            }

            $segments = $arguments['segments'] ?? [];
            if (empty($segments)) {
                return ToolResult::error('VALIDATION_ERROR', 'segments darf nicht leer sein.');
            }

            $isLastBatch = (bool) ($arguments['is_last_batch'] ?? false);

            $recording = WhisperRecording::query()
                ->where('id', (int) $recordingId)
                ->where('team_id', $teamId)
                ->first();

            if (!$recording) {
                return ToolResult::error('NOT_FOUND', "Recording #{$recordingId} nicht gefunden oder kein Zugriff.");
            }

            // Existing segments
            $existingSegments = $recording->segments ?? [];

            // Build index of existing start times for dedup
            $existingStarts = [];
            foreach ($existingSegments as $seg) {
                $existingStarts[(string) ($seg['start'] ?? '')] = true;
            }

            // Append new segments, skip duplicates by start time
            $added = 0;
            $skipped = 0;
            foreach ($segments as $seg) {
                $startKey = (string) ($seg['start'] ?? '');
                if (isset($existingStarts[$startKey])) {
                    $skipped++;
                    continue;
                }
                $existingSegments[] = $seg;
                $existingStarts[$startKey] = true;
                $added++;
            }

            $recording->segments = $existingSegments;

            if ($isLastBatch) {
                // Build transcript from all segments
                $speakerMap = $recording->speaker_map ?? [];
                $transcript = implode("\n\n", array_map(
                    function ($seg) use ($speakerMap) {
                        $speaker = $seg['speaker'] ?? 'A';
                        $displayName = $speakerMap[$speaker] ?? $speaker;
                        return $displayName . ': ' . ($seg['text'] ?? '');
                    },
                    $existingSegments
                ));

                $recording->transcript = $transcript;

                // Count unique speakers
                $speakerLabels = [];
                foreach ($existingSegments as $seg) {
                    $speakerLabels[$seg['speaker'] ?? 'A'] = true;
                }
                $recording->speakers_count = count($speakerLabels);
                $recording->status = WhisperRecording::STATUS_COMPLETED;
            }

            $recording->save();

            $totalSegments = count($existingSegments);

            $result = [
                'recording_id' => $recording->id,
                'segments_added' => $added,
                'segments_skipped' => $skipped,
                'segments_total' => $totalSegments,
                'is_last_batch' => $isLastBatch,
            ];

            if ($isLastBatch) {
                $result['status'] = 'completed';
                $result['speakers_count'] = $recording->speakers_count;
                $result['message'] = "Finalisiert: {$totalSegments} Segmente, {$recording->speakers_count} Speaker. Recording ist jetzt vollständig.";
            } else {
                $result['status'] = 'processing';
                $result['message'] = "{$added} Segmente angehängt ({$totalSegments} total). Weitere Batches senden oder mit is_last_batch=true finalisieren.";
            }

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anhängen der Segmente: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'recordings', 'segments', 'import', 'append'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
