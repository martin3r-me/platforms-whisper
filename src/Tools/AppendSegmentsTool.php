<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Models\WhisperSegment;
use Platform\Whisper\Models\WhisperSpeaker;
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
        return 'APPEND /whisper/recordings/{id}/segments - Haengt Segmente in Batches an eine bestehende Recording an (erstellt via whisper.recordings.import.POST). '
            . 'Max ~50 Segmente pro Call. Format: speaker (Label), start/end (float, Sekunden), text (string), embedding_key (optional). '
            . 'Dedup via start-Zeit: Segmente mit gleichem start werden uebersprungen — ausser das bestehende Segment hat leeren Text, dann wird es aktualisiert. '
            . 'WICHTIG: Letzten Batch mit is_last_batch=true senden — das finalisiert die Recording (baut Transcript, zaehlt Speaker, setzt status=completed). '
            . 'Fuer Plaud-Import empfohlen: whisper.plaud.sync.POST (ein Call statt mehrere).';
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
                    'description' => 'Speaker-Segmente (max ~50 pro Call). Array von Objekten mit: speaker (string), start (float, Sekunden), end (float, Sekunden), text (string), embedding_key (string, optional: Plaud Voice-UUID).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'speaker' => ['type' => 'string'],
                            'start' => ['type' => 'number'],
                            'end' => ['type' => 'number'],
                            'text' => ['type' => 'string'],
                            'embedding_key' => [
                                'type' => 'string',
                                'description' => 'Optional: Plaud Voice-UUID zur automatischen Speaker-Erkennung.',
                            ],
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

            // --- Speaker Resolution ---
            $embeddingKeys = [];
            foreach ($segments as $seg) {
                $key = trim($seg['embedding_key'] ?? '');
                if ($key !== '') {
                    $embeddingKeys[$key] = true;
                }
            }

            $speakersByEmbedding = [];
            if (!empty($embeddingKeys)) {
                $existingSpeakers = WhisperSpeaker::query()
                    ->where('team_id', $teamId)
                    ->whereIn('embedding_key', array_keys($embeddingKeys))
                    ->get()
                    ->keyBy('embedding_key');

                foreach ($embeddingKeys as $key => $_) {
                    if ($existingSpeakers->has($key)) {
                        $speakersByEmbedding[$key] = $existingSpeakers->get($key);
                    } else {
                        $speakersByEmbedding[$key] = WhisperSpeaker::create([
                            'team_id' => $teamId,
                            'name' => 'Speaker ' . (count($speakersByEmbedding) + 1),
                            'embedding_key' => $key,
                            'source' => 'plaud',
                        ]);
                    }
                }
            }

            // --- Existing segments (JSON legacy) ---
            $existingSegments = $recording->segments ?? [];

            // Build index of existing start times for dedup (track text content)
            $existingStarts = [];
            foreach ($existingSegments as $idx => $seg) {
                $existingStarts[(string) ($seg['start'] ?? '')] = [
                    'index' => $idx,
                    'has_text' => trim((string) ($seg['text'] ?? '')) !== '',
                ];
            }

            // Current sort_order offset for whisper_segments table
            $sortOrder = WhisperSegment::where('whisper_recording_id', $recording->id)->max('sort_order') ?? 0;

            // Append new segments (dual-write: JSON + table)
            $added = 0;
            $skipped = 0;
            $segmentRows = [];

            $updated = 0;

            foreach ($segments as $seg) {
                $startKey = (string) ($seg['start'] ?? '');
                $newText = trim((string) ($seg['text'] ?? ''));

                if (isset($existingStarts[$startKey])) {
                    // Allow overwrite if existing segment has empty text and new one has content
                    if (!$existingStarts[$startKey]['has_text'] && $newText !== '') {
                        $existingSegments[$existingStarts[$startKey]['index']] = $seg;
                        $existingStarts[$startKey]['has_text'] = true;
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                // JSON legacy write
                $existingSegments[] = $seg;
                $existingStarts[$startKey] = ['index' => count($existingSegments) - 1, 'has_text' => $newText !== ''];
                $added++;
                $sortOrder++;

                // Table write
                $embeddingKey = trim($seg['embedding_key'] ?? '');
                $speakerId = null;
                if ($embeddingKey !== '' && isset($speakersByEmbedding[$embeddingKey])) {
                    $speakerId = $speakersByEmbedding[$embeddingKey]->id;
                }

                $segmentRows[] = [
                    'whisper_recording_id' => $recording->id,
                    'whisper_speaker_id' => $speakerId,
                    'speaker_label' => (string) ($seg['speaker'] ?? 'A'),
                    'text' => (string) ($seg['text'] ?? ''),
                    'start_seconds' => (float) ($seg['start'] ?? 0),
                    'end_seconds' => (float) ($seg['end'] ?? 0),
                    'embedding_key' => $embeddingKey !== '' ? $embeddingKey : null,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert segment rows
            if (!empty($segmentRows)) {
                WhisperSegment::insert($segmentRows);
            }

            // Update table rows for overwritten segments (empty text → new text)
            if ($updated > 0) {
                foreach ($segments as $seg) {
                    $startSeconds = (float) ($seg['start'] ?? 0);
                    $newText = trim((string) ($seg['text'] ?? ''));
                    if ($newText === '') {
                        continue;
                    }

                    WhisperSegment::where('whisper_recording_id', $recording->id)
                        ->where('start_seconds', $startSeconds)
                        ->where('text', '')
                        ->update([
                            'text' => $newText,
                            'updated_at' => now(),
                        ]);
                }
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
                'segments_updated' => $updated,
                'segments_skipped' => $skipped,
                'segments_total' => $totalSegments,
                'is_last_batch' => $isLastBatch,
            ];

            if (!empty($speakersByEmbedding)) {
                $result['speakers_resolved'] = array_map(fn ($s) => [
                    'id' => $s->id,
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'embedding_key' => $s->embedding_key,
                ], array_values($speakersByEmbedding));
            }

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
