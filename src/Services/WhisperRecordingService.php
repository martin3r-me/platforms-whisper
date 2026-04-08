<?php

namespace Platform\Whisper\Services;

use Platform\Whisper\Models\WhisperRecording;

class WhisperRecordingService
{
    public function update(WhisperRecording $recording, array $payload): WhisperRecording
    {
        $allowed = ['title', 'transcript', 'language'];
        $update = array_intersect_key($payload, array_flip($allowed));
        if (!empty($update)) {
            $recording->update($update);
            $recording->refresh();
        }
        return $recording;
    }

    public function delete(WhisperRecording $recording): void
    {
        $recording->delete();
    }
}
