<?php

namespace Platform\Whisper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\AssemblyAiTranscriptionService;
use Throwable;

class TranscribeRecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800; // 30 min
    public int $tries = 1;

    public function __construct(
        public int $recordingId,
        public string $audioPath,
        public string $language = 'de',
        public bool $multichannel = false,
    ) {
    }

    public function handle(
        AssemblyAiTranscriptionService $transcription,
    ): void {
        $recording = WhisperRecording::find($this->recordingId);
        if (!$recording) {
            $this->safeUnlink($this->audioPath);
            return;
        }

        try {
            $recording->update(['status' => WhisperRecording::STATUS_PROCESSING]);

            $result = $transcription->transcribe(
                $this->audioPath,
                basename($this->audioPath),
                $this->language === 'auto' ? null : $this->language,
                (bool) config('whisper.assemblyai.speaker_labels', true),
                $this->multichannel,
            );

            $finalTranscript = (string) ($result['transcript'] ?? '');
            $segments = $result['segments'] ?? [];
            $speakersCount = (int) ($result['speakers_count'] ?? 0);
            $detectedLang = $result['language'] ?? null;
            $duration = $result['duration'] ?? null;

            $update = [
                'transcript' => $finalTranscript,
                'segments' => !empty($segments) ? $segments : null,
                'speakers_count' => $speakersCount > 0 ? $speakersCount : null,
                'language' => $detectedLang,
                'status' => WhisperRecording::STATUS_COMPLETED,
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
            $preservedPath = $this->preserveAudioOnFailure($this->audioPath, $recording);

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
     * Persist the original audio file via ContextFileService — keeps it on the
     * platform's default storage disk (S3 in production), under a stable folder
     * convention.
     *
     * On failure the transcript itself is still valid, so the recording stays
     * status=completed — but we set error_message so the show page can flag
     * the audio gap. Silent log-only fallbacks bit us in production: every
     * dual-channel test recording landed with files:[] and no clue why.
     */
    private function persistAudio(WhisperRecording $recording): void
    {
        if (!is_file($this->audioPath)) {
            $this->recordPersistAudioWarning($recording, 'Audio-File auf Disk nicht gefunden, bevor Persist startete.');
            return;
        }
        if (!class_exists(\Platform\Core\Services\ContextFileService::class)) {
            $this->recordPersistAudioWarning($recording, 'Platform\\Core\\Services\\ContextFileService nicht geladen.');
            return;
        }

        try {
            $year = $recording->created_at?->format('Y') ?? date('Y');
            $month = $recording->created_at?->format('m') ?? date('m');
            $folder = "whisper/audio/{$recording->team_id}/{$year}/{$month}";

            $mime = @mime_content_type($this->audioPath) ?: 'audio/webm';
            $originalName = 'recording-' . $recording->uuid . '.' . pathinfo($this->audioPath, PATHINFO_EXTENSION);

            // UploadedFile in test mode lets us hand a server-side path to the
            // service without it complaining about the missing PHP upload check.
            $file = new UploadedFile($this->audioPath, $originalName, $mime, null, true);

            $result = app(\Platform\Core\Services\ContextFileService::class)->uploadForContext(
                file: $file,
                contextType: \Platform\Whisper\Models\WhisperRecording::class,
                contextId: $recording->id,
                options: [
                    'folder' => $folder,
                    'team_id' => $recording->team_id,
                    'user_id' => $recording->created_by_user_id,
                ],
            );

            // ContextFileService::uploadForContext returns the file's primary
            // key under 'id' — earlier code looked for 'context_file_id'
            // (copy-paste from a non-existent key) and silently never
            // attached the audio reference.
            if (empty($result['id'])) {
                $this->recordPersistAudioWarning(
                    $recording,
                    'ContextFileService::uploadForContext lieferte keine id zurueck.'
                );
                return;
            }

            $recording->addFileReference(
                (int) $result['id'],
                ['kind' => 'audio_original', 'persisted_by' => 'TranscribeRecordingJob'],
            );
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
