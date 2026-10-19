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
        if (!$recording->wasChanged('status')) {
            return;
        }
        if ($recording->status !== WhisperRecording::STATUS_COMPLETED) {
            return;
        }

        // Soft-coupled — ohne gebundene Ingestion-Naht tut der Job nichts.
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

    public function created(WhisperRecording $recording): void
    {
        // If a recording is created already with status=completed (e.g. Plaud
        // sync of an already-transcribed file), fire on create too.
        if ($recording->status === WhisperRecording::STATUS_COMPLETED) {
            $this->updated($recording);
        }
    }
}
