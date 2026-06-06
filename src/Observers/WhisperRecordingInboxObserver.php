<?php

namespace Platform\Whisper\Observers;

use Platform\Whisper\Jobs\IngestRecordingToInboxJob;
use Platform\Whisper\Models\WhisperRecording;

/**
 * Bridges Whisper-completed recordings into the Inbox module.
 *
 * Fires when a recording transitions from non-completed to completed,
 * dispatching an async job that hands off the transcript + segments +
 * speakers to InboxAudioIngestionService. Soft-coupled: if Inbox isn't
 * installed, the job no-ops.
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

        // The bridge itself stays soft-coupled — if Inbox isn't loaded, do nothing.
        if (!class_exists(\Platform\Inbox\Services\InboxAudioIngestionService::class)) {
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
