<?php

namespace Platform\Whisper\Observers;

use Platform\Whisper\Jobs\IngestRecordingToInboxJob;
use Platform\Whisper\Models\WhisperRecording;

/**
 * Bridges Whisper-completed recordings into the Eingang over the Core
 * RecordingIngestion seam.
 *
 * Fires when a recording transitions from non-completed to completed,
 * dispatching an async job that hands the transcript + segments + speakers
 * to the Core seam. Soft-coupled: if no ingestion impl is bound (inbox absent),
 * the job no-ops. Whisper references no Inbox namespace directly.
 */
class WhisperRecordingInboxObserver
{
    public function updated(WhisperRecording $recording): void
    {
        // Übergang non-completed → completed (z.B. Browser-Upload nach Transkription).
        if (!$recording->wasChanged('status')) {
            return;
        }
        if ($recording->status !== WhisperRecording::STATUS_COMPLETED) {
            return;
        }

        $this->dispatchIngest($recording);
    }

    public function created(WhisperRecording $recording): void
    {
        // Plaud-synced recordings werden bereits mit status=completed INSERTed.
        // Beim Insert füllt Eloquent die changed-attributes NICHT (syncChanges()
        // läuft nur in performUpdate), d.h. wasChanged('status') ist im created-
        // Event IMMER false. Früher lief created() über updated() und blieb an
        // genau diesem Gate hängen → die Aufnahme erreichte den Eingang nie.
        // Deshalb hier direkt dispatchen, ohne das wasChanged-Gate.
        if ($recording->status === WhisperRecording::STATUS_COMPLETED) {
            $this->dispatchIngest($recording);
        }
    }

    /**
     * Dispatcht den Ingest-Job in den Eingang. Soft-coupled: ohne gebundene
     * RecordingIngestion-Naht (inbox-Modul abwesend) ein No-op.
     */
    private function dispatchIngest(WhisperRecording $recording): void
    {
        if (!interface_exists(\Platform\Core\Inbox\Contracts\RecordingIngestion::class)) {
            return;
        }

        try {
            IngestRecordingToInboxJob::dispatch($recording->id);
        } catch (\Throwable $e) {
            \Log::warning('Whisper→Inbox: dispatch failed', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
