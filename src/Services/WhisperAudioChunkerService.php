<?php

namespace Platform\Whisper\Services;

use Illuminate\Support\Str;
use RuntimeException;

class WhisperAudioChunkerService
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct()
    {
        $this->ffmpegPath = config('whisper.ffmpeg_path', 'ffmpeg');
        $this->ffprobePath = config('whisper.ffprobe_path', 'ffprobe');
    }

    /**
     * Splits audio into N-second segments using ffmpeg.
     * Re-encodes to opus 24kbit/s for size efficiency (Whisper-friendly).
     *
     * @return array{files: string[], tmp_dir: string}
     */
    public function chunk(string $inputPath, int $segmentSeconds = 600): array
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException("Audio file not found: {$inputPath}");
        }

        $tmpDir = sys_get_temp_dir() . '/whisper-' . Str::random(16);
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("Could not create tmp dir: {$tmpDir}");
        }

        $pattern = $tmpDir . '/chunk_%03d.ogg';

        $cmd = sprintf(
            '%s -hide_banner -loglevel error -y -i %s -vn -f segment -segment_time %d -c:a libopus -b:a 24k -ac 1 -ar 16000 -reset_timestamps 1 %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($inputPath),
            $segmentSeconds,
            escapeshellarg($pattern)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->cleanup($tmpDir);
            throw new RuntimeException('ffmpeg chunking failed (exit ' . $returnCode . '): ' . implode("\n", $output));
        }

        $files = glob($tmpDir . '/chunk_*.ogg') ?: [];
        sort($files);

        if (empty($files)) {
            $this->cleanup($tmpDir);
            throw new RuntimeException('ffmpeg produced no chunks. Input may be invalid.');
        }

        return ['files' => $files, 'tmp_dir' => $tmpDir];
    }

    /**
     * Re-encodes a single file to small opus (no chunking).
     */
    public function compress(string $inputPath): string
    {
        $tmpDir = sys_get_temp_dir() . '/whisper-' . Str::random(16);
        @mkdir($tmpDir, 0755, true);
        $out = $tmpDir . '/compressed.ogg';

        $cmd = sprintf(
            '%s -hide_banner -loglevel error -y -i %s -vn -c:a libopus -b:a 24k -ac 1 -ar 16000 %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($inputPath),
            escapeshellarg($out)
        );
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0 || !is_file($out)) {
            throw new RuntimeException('ffmpeg compress failed: ' . implode("\n", $output));
        }
        return $out;
    }

    public function getDuration(string $inputPath): ?float
    {
        // Try ffprobe first
        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellcmd($this->ffprobePath),
            escapeshellarg($inputPath)
        );
        exec($cmd, $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            $val = trim((string) $output[0]);
            if (is_numeric($val)) {
                return (float) $val;
            }
        }

        // Fallback: parse ffmpeg stderr
        $cmd2 = sprintf(
            '%s -hide_banner -i %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($inputPath)
        );
        exec($cmd2, $out2);
        $text = implode("\n", $out2);
        if (preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $text, $m)) {
            return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
        }
        return null;
    }

    public function cleanup(string $tmpDir): void
    {
        if (!$tmpDir || !is_dir($tmpDir)) {
            return;
        }
        foreach ((array) glob($tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);
    }
}
