<?php

namespace Platform\Whisper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\AssemblyAiTranscriptionService;
use Platform\Whisper\Services\WhisperSummaryService;
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
        public string $language = 'de'
    ) {
    }

    public function handle(
        AssemblyAiTranscriptionService $transcription,
        WhisperSummaryService $summarizer
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
                (bool) config('whisper.assemblyai.speaker_labels', true)
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

            // LLM-Zusammenfassung: Titel + Summary
            $llm = $summarizer->summarize($finalTranscript, $detectedLang ?? $this->language);

            $hasDefaultTitle = !$recording->title || str_starts_with((string) $recording->title, 'Aufnahme vom ');

            if ($hasDefaultTitle) {
                if (!empty($llm['title'])) {
                    $update['title'] = $llm['title'];
                } else {
                    $fallback = $this->generateTitle($finalTranscript);
                    if ($fallback) {
                        $update['title'] = $fallback;
                    }
                }
            }

            if (!empty($llm['summary'])) {
                $update['summary'] = $llm['summary'];
            }

            $recording->update($update);
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
