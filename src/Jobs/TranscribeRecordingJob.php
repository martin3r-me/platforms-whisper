<?php

namespace Platform\Whisper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\LocalTranscriptionService;
use Platform\Whisper\Services\RecordingAudioStore;
use Throwable;

class TranscribeRecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600; // 60 min — Dual-Spur = zwei Durchläufe auf dem eigenen Server
    public int $tries = 1;

    public function __construct(
        public int $recordingId,
        public string $audioPath,
        public string $language = 'de',
        public bool $multichannel = false,
    ) {
    }

    public function handle(
        LocalTranscriptionService $transcription,
    ): void {
        $recording = WhisperRecording::find($this->recordingId);
        if (!$recording) {
            $this->safeUnlink($this->audioPath);
            return;
        }

        try {
            $recording->update(['status' => WhisperRecording::STATUS_PROCESSING]);

            $result = $transcription->transcribe($this->audioPath, $this->language, $this->multichannel);

            $finalTranscript = (string) ($result['transcript'] ?? '');
            $segments = $result['segments'] ?? [];
            $speakersCount = (int) ($result['speakers_count'] ?? 0);
            $detectedLang = $result['language'] ?? null;
            $duration = $result['duration'] ?? null;

            $update = [
                'transcript' => $finalTranscript,
                'segments' => !empty($segments) ? $segments : null,
                'speakers_count' => $speakersCount > 0 ? $speakersCount : null,
                'language' => $detectedLang ?? $recording->language,
                'status' => WhisperRecording::STATUS_COMPLETED,
                'error_message' => null, // alter Fehler (z. B. vor whisper:retranscribe) ist erledigt
                'model' => $result['model'] ?? $recording->model,
                'provider_id' => $result['provider_id'] ?? null,
            ];

            if ($duration !== null) {
                $update['duration_seconds'] = (int) round((float) $duration);
            }

            // Higher-level layers (summary, action items, semantic title) live
            // in the Inbox module's enrichment pipeline now — Whisper's scope
            // is transcript + speakers + segments only.

            // Persist the original audio FIRST, then set status=completed.
            // The inbox observer fires on the completed transition and
            // expects the audio reference to already exist on the recording;
            // otherwise the inbox item gets created without playback.
            $statusUpdate = $update;
            unset($update['status']);
            $recording->update($update);
            $this->persistAudio($recording);
            $recording->update(['status' => $statusUpdate['status']]);
        } catch (Throwable $e) {
            // Ist das Audio gesichert, reicht whisper:retranscribe — die Tmp-Datei kann weg.
            $preservedPath = $recording->audioReference()
                ? null
                : $this->preserveAudioOnFailure($this->audioPath, $recording);
            if ($preservedPath === null) {
                $this->safeUnlink($this->audioPath);
            }

            Log::error('Whisper transcription job failed', [
                'recording_id' => $this->recordingId,
                'error' => $e->getMessage(),
                'audio_size_bytes' => is_file($this->audioPath)
                    ? filesize($this->audioPath)
                    : null,
                'preserved_at' => $preservedPath,
                'trace' => $e->getTraceAsString(),
            ]);

            $recording->update([
                'status' => WhisperRecording::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            return; // kein finally-unlink — preserveAudioOnFailure hat verschoben
        }
        $this->safeUnlink($this->audioPath);
    }

    public function failed(Throwable $e): void
    {
        $recording = WhisperRecording::find($this->recordingId);
        $recording?->update([
            'status' => WhisperRecording::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
        ]);
        $this->safeUnlink($this->audioPath);
    }

    private function safeUnlink(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Verschiebt das Audio-File auf failure nach storage/app/whisper-failed/,
     * damit die kaputte Datei für Diagnose erhalten bleibt statt im finally
     * gelöscht zu werden. Gibt den neuen Pfad zurück (oder null bei Fehler).
     */
    private function preserveAudioOnFailure(string $path, WhisperRecording $recording): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $dir = storage_path('app/whisper-failed');
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $stamp = date('Ymd-His');
            $base = $recording->id . '-' . $stamp . '-' . basename($path);
            $dest = $dir . '/' . $base;
            if (@rename($path, $dest)) {
                return $dest;
            }
        } catch (Throwable) {
            // ignore
        }
        return null;
    }

    /**
     * Sichert das Original-Audio, falls das beim Eingang nicht geklappt hat
     * (oder die Aufnahme noch über den alten Weg kam). Ist schon Audio
     * gesichert, passiert nichts.
     *
     * On failure the transcript itself is still valid, so the recording stays
     * status=completed — but we set error_message so the show page can flag
     * the audio gap. Silent log-only fallbacks bit us in production: every
     * dual-channel test recording landed with files:[] and no clue why.
     */
    private function persistAudio(WhisperRecording $recording): void
    {
        try {
            app(RecordingAudioStore::class)->persist($recording, $this->audioPath, 'TranscribeRecordingJob');
        } catch (Throwable $e) {
            $this->recordPersistAudioWarning($recording, $e->getMessage());
        }
    }

    /**
     * Writes the audio-persist warning to the recording's error_message so it
     * surfaces in the UI, while leaving status=completed because the transcript
     * itself is still valid. Also logs for ops.
     */
    private function recordPersistAudioWarning(WhisperRecording $recording, string $message): void
    {
        Log::warning('Whisper: audio persistence failed', [
            'recording_id' => $recording->id,
            'error' => $message,
        ]);

        $line = 'Audio-Persist: ' . trim($message);
        $existing = (string) ($recording->error_message ?? '');
        $combined = $existing === '' ? $line : ($existing . "\n" . $line);
        $recording->update([
            'error_message' => mb_substr($combined, 0, 1000),
        ]);
    }
}
