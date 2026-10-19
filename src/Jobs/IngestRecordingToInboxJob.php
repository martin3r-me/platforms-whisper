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
        // Aufnahme-Ingestion über die Core-Naht — whisper kennt das inbox-Modul
        // nicht mehr (Materialisierung liegt hinter RecordingIngestion).
        $contract = '\\Platform\\Core\\Inbox\\Contracts\\RecordingIngestion';
        if (!interface_exists($contract)) {
            return;
        }

        $recording = WhisperRecording::with('segmentRows')->find($this->recordingId);
        if (!$recording || $recording->status !== WhisperRecording::STATUS_COMPLETED) {
            return;
        }

        $audioRef = $this->resolveAudioReference($recording);

        // Build the neutral ingestion payload.
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
            'audio_file' => $audioRef,
        ];

        try {
            $itemId = app($contract)->ingest($payload);
        } catch (\Throwable $e) {
            $this->recordWarning($recording, 'ingest failed: ' . $e->getMessage());
            return;
        }

        if (!$itemId) {
            $this->recordWarning($recording, 'ingest returned null — payload likely missing a required field.');
            return;
        }

        // If the Mac client supplied a target meeting at upload time, assign the
        // recording to it over the Core ItemLinking seam (supplements + Org-Kaskade).
        // Whisper never touches Inbox tables/models directly.
        $this->linkTargetIfNeeded($recording, (int) $itemId);
        // Korrelation (Transkript→Termin) läuft bereits inline in der Ingestion
        // (LinkRule) für JEDE Aufnahme — kein zusätzliches Event nötig.
    }

    /**
     * Assigns the freshly-ingested recording to the target meeting the Mac client
     * passed at upload — over the Core ItemLinking seam. Soft-coupled: if the seam
     * isn't bound or the target vanished, we log a warning and move on; the
     * recording item still stands on its own.
     */
    private function linkTargetIfNeeded(WhisperRecording $recording, int $recordingItemId): void
    {
        $targetId = (int) ($recording->target_inbox_item_id ?? 0);
        if ($targetId <= 0) {
            return;
        }

        $contract = '\\Platform\\Core\\Inbox\\Contracts\\ItemLinking';
        if (!interface_exists($contract)) {
            $this->recordWarning($recording, 'ItemLinking not loaded — no link created.');
            return;
        }

        try {
            app($contract)->assign($recordingItemId, $targetId);
            \Log::info('Whisper→Inbox: assigned recording to target', [
                'recording_id' => $recording->id,
                'from' => $recordingItemId,
                'to' => $targetId,
            ]);
        } catch (\Throwable $e) {
            $this->recordWarning(
                $recording,
                'Link to meeting #' . $targetId . ' failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Surfaces an Inbox-bridge problem on the Whisper recording so the show
     * page can flag it. Status stays untouched — the transcript itself is
     * fine; only the downstream bridge had trouble.
     */
    private function recordWarning(WhisperRecording $recording, string $message): void
    {
        \Log::warning('Whisper→Inbox: ' . $message, [
            'recording_id' => $recording->id,
        ]);

        $line = 'Inbox-Bridge: ' . trim($message);
        $existing = (string) ($recording->error_message ?? '');
        $combined = $existing === '' ? $line : ($existing . "\n" . $line);
        $recording->update([
            'error_message' => mb_substr($combined, 0, 1000),
        ]);
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
