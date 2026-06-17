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
use Platform\Whisper\Services\AssemblyAiLemurService;
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
        AssemblyAiLemurService $lemur
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

            // LeMUR: Titel + Summary + Action Items in einem Task-Call.
            $providerId = (string) ($result['provider_id'] ?? '');
            $insights = $providerId !== ''
                ? $lemur->generateInsights($providerId, $detectedLang ?? $this->language)
                : ['title' => null, 'summary' => null, 'action_items' => null];

            $hasDefaultTitle = !$recording->title || str_starts_with((string) $recording->title, 'Aufnahme vom ');

            if ($hasDefaultTitle) {
                if (!empty($insights['title'])) {
                    $update['title'] = $insights['title'];
                } else {
                    $fallback = $this->generateTitle($finalTranscript);
                    if ($fallback) {
                        $update['title'] = $fallback;
                    }
                }
            }

            if (!empty($insights['summary'])) {
                $update['summary'] = $insights['summary'];
            }
            if (!empty($insights['action_items'])) {
                $update['action_items'] = $insights['action_items'];
            }

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
            Log::error('Whisper transcription job failed', [
                'recording_id' => $this->recordingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $recording->update([
                'status' => WhisperRecording::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        } finally {
            $this->safeUnlink($this->audioPath);
        }
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
     * Persist the original audio file via ContextFileService — keeps it on the
     * platform's default storage disk (S3 in production), under a stable folder
     * convention. Soft-coupled: if ContextFileService is missing or the file is
     * already gone, just log and move on (transcript stays usable).
     */
    private function persistAudio(WhisperRecording $recording): void
    {
        if (!is_file($this->audioPath)) {
            return;
        }
        if (!class_exists(\Platform\Core\Services\ContextFileService::class)) {
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

            if (!empty($result['context_file_id'])) {
                $recording->addFileReference(
                    (int) $result['context_file_id'],
                    ['kind' => 'audio_original', 'persisted_by' => 'TranscribeRecordingJob'],
                );
            }
        } catch (Throwable $e) {
            Log::warning('Whisper: audio persistence failed', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fallback-Titel aus dem Transkript: erster Satz, max. 80 Zeichen.
     */
    private function generateTitle(string $transcript): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $transcript));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^(.*?[\.\!\?])(\s|$)/u', $clean, $m)) {
            $sentence = trim($m[1]);
        } else {
            $sentence = $clean;
        }

        if (mb_strlen($sentence) > 80) {
            $sentence = mb_substr($sentence, 0, 77) . '…';
        }

        return $sentence ?: null;
    }
}
