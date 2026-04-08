<?php

namespace Platform\Whisper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\WhisperAudioChunkerService;
use Platform\Whisper\Services\WhisperTranscriptionService;
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

    public function handle(WhisperTranscriptionService $whisper, WhisperAudioChunkerService $chunker): void
    {
        $recording = WhisperRecording::find($this->recordingId);
        if (!$recording) {
            $this->safeUnlink($this->audioPath);
            return;
        }

        $tmpDir = null;

        try {
            $recording->update(['status' => WhisperRecording::STATUS_PROCESSING]);

            // Duration ermitteln
            $duration = $chunker->getDuration($this->audioPath);
            if ($duration) {
                $recording->update(['duration_seconds' => (int) round($duration)]);
            }

            $maxBytes = (int) config('whisper.chunk_threshold_bytes', 20 * 1024 * 1024);
            $segmentSeconds = (int) config('whisper.segment_seconds', 600);

            $fileSize = @filesize($this->audioPath) ?: 0;
            $needsChunking = $fileSize > $maxBytes;

            if ($needsChunking) {
                $result = $chunker->chunk($this->audioPath, $segmentSeconds);
                $chunks = $result['files'];
                $tmpDir = $result['tmp_dir'];
            } else {
                // Single chunk: trotzdem komprimieren wenn größer als ~10 MB
                if ($fileSize > 10 * 1024 * 1024) {
                    $compressed = $chunker->compress($this->audioPath);
                    $chunks = [$compressed];
                    $tmpDir = dirname($compressed);
                } else {
                    $chunks = [$this->audioPath];
                }
            }

            $recording->update([
                'chunks_total' => count($chunks),
                'chunks_done' => 0,
            ]);

            $transcripts = [];
            $detectedLang = null;

            foreach ($chunks as $idx => $chunkPath) {
                $result = $whisper->transcribe(
                    $chunkPath,
                    'audio_' . str_pad((string) $idx, 3, '0', STR_PAD_LEFT) . '.ogg',
                    $this->language
                );

                $transcripts[] = trim((string) $result['transcript']);

                if (!$detectedLang && !empty($result['language'])) {
                    $detectedLang = $result['language'];
                }

                // Inkrementeller Fortschritt → UI-Polling sieht es live
                $recording->update([
                    'chunks_done' => $idx + 1,
                    'transcript' => implode("\n\n", array_filter($transcripts)),
                    'language' => $detectedLang,
                ]);
            }

            $finalTranscript = implode("\n\n", array_filter($transcripts));

            $update = [
                'transcript' => $finalTranscript,
                'language' => $detectedLang,
                'status' => WhisperRecording::STATUS_COMPLETED,
            ];

            // Auto-Title aus erstem Satz wenn Default-Titel ("Aufnahme vom ...")
            $smartTitle = $this->generateTitle($finalTranscript);
            if ($smartTitle && (!$recording->title || str_starts_with((string) $recording->title, 'Aufnahme vom '))) {
                $update['title'] = $smartTitle;
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
            if ($tmpDir) {
                $chunker->cleanup($tmpDir);
            }
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
     * Erzeugt einen kurzen Titel aus dem Transkript:
     * Erster Satz, max. 80 Zeichen, Leerstellen-bereinigt.
     */
    private function generateTitle(string $transcript): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $transcript));
        if ($clean === '') {
            return null;
        }

        // Ersten Satz extrahieren (Punkt, Frage-, Ausrufezeichen)
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
