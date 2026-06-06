<?php

namespace Platform\Whisper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Whisper\Models\WhisperRecording;

class IngestRecordingToInboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public int $recordingId) {}

    public function handle(): void
    {
        $serviceClass = '\\Platform\\Inbox\\Services\\InboxAudioIngestionService';
        if (!class_exists($serviceClass)) {
            return;
        }

        $recording = WhisperRecording::with('segmentRows')->find($this->recordingId);
        if (!$recording || $recording->status !== WhisperRecording::STATUS_COMPLETED) {
            return;
        }

        // Build the contract payload that Inbox expects.
        $payload = [
            'team_id' => $recording->team_id,
            'user_id' => $recording->created_by_user_id,
            'source_type' => 'whisper_recording',
            'source_id' => $recording->id,
            'title' => $recording->title,
            'body' => (string) ($recording->transcript ?? ''),
            'language' => $recording->language,
            'audio_duration_seconds' => $recording->duration_seconds,
            'audio_recorded_at' => $recording->recorded_at ?? $recording->created_at,
            'speakers' => $this->collectSpeakers($recording),
            'segments' => $this->collectSegments($recording),
            // Audio reference — TranscribeRecordingJob persists the original
            // upload via ContextFileService before discarding the tmp file.
            // We hand the resulting context_file_id over to Inbox, which adds
            // its own reference (kind=audio_original) on the inbox_item.
            'audio_file' => $this->resolveAudioReference($recording),
        ];

        try {
            app($serviceClass)->ingest($payload);
        } catch (\Throwable $e) {
            \Log::warning('Whisper→Inbox: ingest failed', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Look up the original-audio ContextFileReference attached to the recording
     * and hand its context_file_id to Inbox. Returns null when no audio is
     * persisted yet (older recordings before the persistAudio patch landed).
     */
    protected function resolveAudioReference(WhisperRecording $recording): ?array
    {
        $ref = $recording->getOrderedFileReferences()
            ->first(fn ($r) => ($r->meta['kind'] ?? null) === 'audio_original');

        if (!$ref || !$ref->context_file_id) {
            return null;
        }

        return ['context_file_id' => (int) $ref->context_file_id];
    }

    protected function collectSpeakers(WhisperRecording $recording): array
    {
        $speakerMap = $recording->speaker_map ?? [];
        $speakers = [];

        foreach ($speakerMap as $label => $info) {
            $displayName = is_array($info)
                ? ($info['name'] ?? $info['display_name'] ?? null)
                : (is_string($info) ? $info : null);
            $entityId = is_array($info) ? ($info['entity_id'] ?? null) : null;

            $speakers[] = [
                'label' => (string) $label,
                'display_name' => $displayName,
                'entity_id' => $entityId ? (int) $entityId : null,
            ];
        }

        // Fallback: derive from segments if speaker_map is empty.
        if (empty($speakers) && $recording->segmentRows) {
            $labels = $recording->segmentRows
                ->pluck('speaker_label')
                ->filter()
                ->unique()
                ->values();
            foreach ($labels as $label) {
                $speakers[] = [
                    'label' => (string) $label,
                    'display_name' => null,
                    'entity_id' => null,
                ];
            }
        }

        return $speakers;
    }

    protected function collectSegments(WhisperRecording $recording): array
    {
        if ($recording->segmentRows && $recording->segmentRows->isNotEmpty()) {
            return $recording->segmentRows->map(fn ($s) => [
                'start_seconds' => (int) round($s->start_seconds ?? 0),
                'end_seconds' => (int) round($s->end_seconds ?? 0),
                'speaker_label' => $s->speaker_label,
                'text' => (string) ($s->text ?? ''),
                'confidence' => $s->confidence ?? null,
            ])->all();
        }

        // Fallback: JSON column on the recording.
        $segments = $recording->segments ?? [];
        if (!is_array($segments)) {
            return [];
        }
        return array_map(fn ($s) => [
            'start_seconds' => (int) round($s['start'] ?? $s['start_seconds'] ?? 0),
            'end_seconds' => (int) round($s['end'] ?? $s['end_seconds'] ?? 0),
            'speaker_label' => $s['speaker'] ?? $s['speaker_label'] ?? null,
            'text' => (string) ($s['text'] ?? ''),
            'confidence' => $s['confidence'] ?? null,
        ], $segments);
    }
}
