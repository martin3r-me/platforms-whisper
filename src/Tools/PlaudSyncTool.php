<?php

namespace Platform\Whisper\Tools;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Models\WhisperSegment;
use Platform\Whisper\Models\WhisperSpeaker;
use Platform\Whisper\Services\PlaudNoteParser;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class PlaudSyncTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.plaud.sync.POST';
    }

    public function getDescription(): string
    {
        return 'POST /whisper/plaud/sync - Importiert eine komplette Plaud-Aufnahme atomar in einem Call. '
            . 'Erwartet: file_id, title, note_content (Markdown aus get_note), segments (Array aus get_transcript), metadata (start_at, duration_ms, serial_number). '
            . 'Automatisch geparst aus note_content: Summary, Action Items, AI Suggestions, Outline. '
            . 'Speaker werden automatisch via embedding_key resolved/erstellt. '
            . 'Duplikat-Erkennung via file_id. Entity-Verknuepfung NICHT hier — danach via organization.dimension_links.POST.';
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
                'file_id' => [
                    'type' => 'string',
                    'description' => 'Plaud file ID (Dedup-Key). Wird als provider_id "plaud:{file_id}" gespeichert.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Titel der Aufnahme.',
                ],
                'note_content' => [
                    'type' => 'string',
                    'description' => 'Vollständiger Markdown-Inhalt aus get_note.data_content. Wird automatisch in Summary, Action Items, AI Suggestions und Outline geparst.',
                ],
                'segments' => [
                    'type' => 'array',
                    'description' => 'Transcript-Segmente aus get_transcript. Array von Objekten.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'Gesprochener Text des Segments. Alternativ auch als "text" akzeptiert.',
                            ],
                            'start_time' => [
                                'type' => 'integer',
                                'description' => 'Start-Zeitpunkt in Millisekunden.',
                            ],
                            'end_time' => [
                                'type' => 'integer',
                                'description' => 'End-Zeitpunkt in Millisekunden.',
                            ],
                            'speaker' => [
                                'type' => 'string',
                                'description' => 'Display-Name des Sprechers (z.B. "martin3r").',
                            ],
                            'original_speaker' => [
                                'type' => 'string',
                                'description' => 'Originale Plaud-Bezeichnung (z.B. "Speaker 1", "Speaker 2").',
                            ],
                            'embeddingKey' => [
                                'type' => 'string',
                                'description' => 'Plaud Voice-UUID für Speaker-Erkennung (null wenn unbekannt).',
                            ],
                        ],
                    ],
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Aufnahme-Metadaten aus get_file.',
                    'properties' => [
                        'serial_number' => [
                            'type' => 'string',
                            'description' => 'Geräte-Seriennummer.',
                        ],
                        'start_at' => [
                            'type' => 'string',
                            'description' => 'Aufnahme-Start als ISO-8601 Zeitstempel. Alternativ auch als "recorded_at" akzeptiert.',
                        ],
                        'duration_ms' => [
                            'type' => 'integer',
                            'description' => 'Aufnahme-Dauer in Millisekunden.',
                        ],
                    ],
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional: ISO-Sprachcode (z.B. "de", "en"). Default: "de".',
                ],
            ],
            'required' => ['file_id', 'title', 'note_content', 'segments', 'metadata'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            // 1. Team Resolution
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            // Validate required fields
            $fileId = trim((string) ($arguments['file_id'] ?? ''));
            $title = trim((string) ($arguments['title'] ?? ''));
            $noteContent = (string) ($arguments['note_content'] ?? '');
            $segments = $arguments['segments'] ?? [];
            $metadata = $arguments['metadata'] ?? [];

            if ($fileId === '') {
                return ToolResult::error('VALIDATION_ERROR', 'file_id ist erforderlich.');
            }
            if ($title === '') {
                return ToolResult::error('VALIDATION_ERROR', 'title ist erforderlich.');
            }
            if (empty($segments)) {
                return ToolResult::error('VALIDATION_ERROR', 'segments darf nicht leer sein.');
            }

            // 2. Dedup-Check
            $providerId = 'plaud:' . $fileId;
            $existing = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->where('provider_id', $providerId)
                ->first();

            if ($existing) {
                return ToolResult::success([
                    'recording_id' => $existing->id,
                    'uuid' => $existing->uuid,
                    'title' => $existing->title,
                    'status' => $existing->status,
                    'duration_seconds' => $existing->duration_seconds,
                    'speakers_count' => $existing->speakers_count,
                    'segments_count' => $existing->segmentRows()->count(),
                    'duplicate' => true,
                    'message' => "Plaud-Recording existiert bereits (ID #{$existing->id}). Kein erneuter Import.",
                ]);
            }

            // 3. Parse note content
            $parser = new PlaudNoteParser();
            $parsed = $parser->parse($noteContent);

            // 4. Map metadata
            $deviceSerial = isset($metadata['serial_number']) ? trim((string) $metadata['serial_number']) : null;
            $recordedAt = null;
            $recordedAtRaw = $metadata['start_at'] ?? $metadata['recorded_at'] ?? null;
            if (!empty($recordedAtRaw)) {
                try {
                    $recordedAt = Carbon::parse($recordedAtRaw);
                } catch (\Throwable) {
                    // Ignore invalid date
                }
            }
            $durationSeconds = isset($metadata['duration_ms']) ? (int) ($metadata['duration_ms'] / 1000) : null;

            // 5. Build speaker map from segments
            $speakerTriplets = []; // embeddingKey|original_speaker => [speaker, original_speaker, embeddingKey]
            foreach ($segments as $seg) {
                $originalSpeaker = trim((string) ($seg['original_speaker'] ?? ''));
                $speakerName = trim((string) ($seg['speaker'] ?? ''));
                $embeddingKey = trim((string) ($seg['embeddingKey'] ?? ''));

                $key = $embeddingKey !== '' ? $embeddingKey : $originalSpeaker;
                if ($key === '') {
                    $key = $speakerName !== '' ? $speakerName : 'unknown';
                }

                if (!isset($speakerTriplets[$key])) {
                    $speakerTriplets[$key] = [
                        'speaker' => $speakerName,
                        'original_speaker' => $originalSpeaker,
                        'embeddingKey' => $embeddingKey !== '' ? $embeddingKey : null,
                    ];
                }
            }

            // Map original_speaker to label (Speaker 1 → A, Speaker 2 → B, etc.)
            $labelMap = []; // original_speaker → label
            $speakerMap = []; // label → display name
            $labelIndex = 0;

            foreach ($speakerTriplets as $triplet) {
                $original = $triplet['original_speaker'];
                if ($original !== '' && !isset($labelMap[$original])) {
                    $label = chr(65 + $labelIndex); // A, B, C, ...
                    $labelMap[$original] = $label;
                    $speakerMap[$label] = $triplet['speaker'] !== '' ? $triplet['speaker'] : $original;
                    $labelIndex++;
                }
            }

            // Resolve WhisperSpeaker records from embedding keys
            $speakersByEmbedding = [];
            $embeddingKeys = array_filter(array_column($speakerTriplets, 'embeddingKey'));

            if (!empty($embeddingKeys)) {
                $existingSpeakers = WhisperSpeaker::query()
                    ->where('team_id', $teamId)
                    ->whereIn('embedding_key', $embeddingKeys)
                    ->get()
                    ->keyBy('embedding_key');

                foreach ($speakerTriplets as $triplet) {
                    $ek = $triplet['embeddingKey'];
                    if ($ek === null) {
                        continue;
                    }

                    if ($existingSpeakers->has($ek)) {
                        $speakersByEmbedding[$ek] = $existingSpeakers->get($ek);
                    } else {
                        $displayName = $triplet['speaker'] !== '' ? $triplet['speaker'] : ('Speaker ' . (count($speakersByEmbedding) + 1));
                        $speakersByEmbedding[$ek] = WhisperSpeaker::create([
                            'team_id' => $teamId,
                            'name' => $displayName,
                            'embedding_key' => $ek,
                            'source' => 'plaud',
                        ]);
                    }
                }
            }

            // 6. Create WhisperRecording
            $recording = WhisperRecording::create([
                'team_id' => $teamId,
                'created_by_user_id' => $context->user?->id,
                'title' => $title,
                'summary' => $parsed['summary'],
                'action_items' => $parsed['action_items'],
                'outline' => $parsed['outline'],
                'ai_suggestions' => $parsed['ai_suggestions'],
                'speaker_map' => !empty($speakerMap) ? $speakerMap : null,
                'language' => $arguments['language'] ?? 'de',
                'duration_seconds' => $durationSeconds,
                'model' => 'import:plaud',
                'provider_id' => $providerId,
                'device_serial' => $deviceSerial,
                'recorded_at' => $recordedAt,
                'status' => WhisperRecording::STATUS_PROCESSING,
            ]);

            // 7. Insert segments (dual-write: JSON + table)
            $jsonSegments = [];
            $segmentRows = [];
            $sortOrder = 0;

            foreach ($segments as $seg) {
                $content = trim((string) ($seg['content'] ?? $seg['text'] ?? ''));
                $originalSpeaker = trim((string) ($seg['original_speaker'] ?? ''));
                $speakerName = trim((string) ($seg['speaker'] ?? ''));
                $embeddingKey = trim((string) ($seg['embeddingKey'] ?? ''));
                $startMs = (int) ($seg['start_time'] ?? 0);
                $endMs = (int) ($seg['end_time'] ?? 0);

                $label = $labelMap[$originalSpeaker] ?? 'A';

                // JSON legacy format (compatible with AppendSegmentsTool)
                $jsonSegments[] = [
                    'speaker' => $label,
                    'text' => $content,
                    'start' => $startMs / 1000,
                    'end' => $endMs / 1000,
                    'embedding_key' => $embeddingKey !== '' ? $embeddingKey : null,
                ];

                // Table row
                $sortOrder++;
                $speakerId = null;
                if ($embeddingKey !== '' && isset($speakersByEmbedding[$embeddingKey])) {
                    $speakerId = $speakersByEmbedding[$embeddingKey]->id;
                }

                $segmentRows[] = [
                    'whisper_recording_id' => $recording->id,
                    'whisper_speaker_id' => $speakerId,
                    'speaker_label' => $label,
                    'text' => $content,
                    'start_seconds' => $startMs / 1000,
                    'end_seconds' => $endMs / 1000,
                    'embedding_key' => $embeddingKey !== '' ? $embeddingKey : null,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert segment rows
            if (!empty($segmentRows)) {
                foreach (array_chunk($segmentRows, 500) as $chunk) {
                    WhisperSegment::insert($chunk);
                }
            }

            // 8. Build transcript text
            $transcript = implode("\n\n", array_map(
                function ($seg) use ($speakerMap) {
                    $speaker = $seg['speaker'] ?? 'A';
                    $displayName = $speakerMap[$speaker] ?? $speaker;
                    return $displayName . ': ' . ($seg['text'] ?? '');
                },
                $jsonSegments
            ));

            // Count unique speakers
            $uniqueSpeakers = [];
            foreach ($jsonSegments as $seg) {
                $uniqueSpeakers[$seg['speaker']] = true;
            }

            // 9. Finalize recording
            $recording->segments = $jsonSegments;
            $recording->transcript = $transcript;
            $recording->speakers_count = count($uniqueSpeakers);
            $recording->status = WhisperRecording::STATUS_COMPLETED;
            $recording->save();

            // Build response
            $result = [
                'recording_id' => $recording->id,
                'uuid' => $recording->uuid,
                'title' => $recording->title,
                'status' => 'completed',
                'duration_seconds' => $recording->duration_seconds,
                'speakers_count' => $recording->speakers_count,
                'segments_count' => count($jsonSegments),
                'parsed_sections' => [
                    'summary' => $parsed['summary'] !== null,
                    'action_items' => $parsed['action_items'] !== null,
                    'ai_suggestions' => $parsed['ai_suggestions'] !== null,
                    'outline' => $parsed['outline'] !== null,
                ],
                'duplicate' => false,
                'message' => 'Plaud-Recording vollständig importiert.',
            ];

            if (!empty($speakersByEmbedding)) {
                $result['speakers_resolved'] = array_map(fn ($s) => [
                    'id' => $s->id,
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'embedding_key' => $s->embedding_key,
                ], array_values($speakersByEmbedding));
            }

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Plaud-Sync: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'plaud', 'sync', 'import', 'recordings'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
