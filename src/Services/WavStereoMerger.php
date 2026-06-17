<?php

namespace Platform\Whisper\Services;

use RuntimeException;

/**
 * Mergt zwei Mono-WAV-Spuren (mic / loopback) zu einer Stereo-WAV.
 *
 * Erwartet beide Eingänge im IDENTISCHEN PCM-Format
 * (gleiche sample rate, gleiche bit depth, mono).
 * Schreibt eine neue Datei mit interleaved L/R-Samples.
 *
 * Bewusst PHP-only — keine ffmpeg-Abhängigkeit auf dem Webserver.
 */
class WavStereoMerger
{
    public function merge(string $leftPath, string $rightPath, string $outPath): void
    {
        $left = $this->readPcmWav($leftPath);
        $right = $this->readPcmWav($rightPath);

        if ($left['sampleRate'] !== $right['sampleRate']) {
            throw new RuntimeException(
                "WAV-Merge: sample rate mismatch ({$left['sampleRate']} vs {$right['sampleRate']})."
            );
        }
        if ($left['bitsPerSample'] !== $right['bitsPerSample']) {
            throw new RuntimeException(
                "WAV-Merge: bit depth mismatch ({$left['bitsPerSample']} vs {$right['bitsPerSample']})."
            );
        }
        if ($left['channels'] !== 1 || $right['channels'] !== 1) {
            throw new RuntimeException('WAV-Merge: beide Spuren müssen mono sein.');
        }

        $bytesPerSample = intdiv($left['bitsPerSample'], 8);

        $lFp = @fopen($leftPath, 'rb');
        $rFp = @fopen($rightPath, 'rb');
        $outFp = @fopen($outPath, 'wb');
        if (!$lFp || !$rFp || !$outFp) {
            if ($lFp) { @fclose($lFp); }
            if ($rFp) { @fclose($rFp); }
            if ($outFp) { @fclose($outFp); }
            throw new RuntimeException('WAV-Merge: konnte Dateien nicht öffnen.');
        }

        try {
            // Beide auf den Start ihres data chunks setzen.
            fseek($lFp, $left['dataOffset']);
            fseek($rFp, $right['dataOffset']);

            $sampleRate = $left['sampleRate'];
            $bits = $left['bitsPerSample'];
            $outChannels = 2;
            $byteRate = $sampleRate * $outChannels * $bytesPerSample;
            $blockAlign = $outChannels * $bytesPerSample;

            // dataSize aus dem Header NICHT blind vertrauen — viele Recorder
            // (Native macOS, Electron, OBS-Loopback) schreiben den Header
            // vor Aufnahme-Ende und aktualisieren das Feld nicht oder setzen
            // 0xFFFFFFFF. Wir klemmen gegen die tatsächliche Restdatei.
            $leftActual = max(0, (filesize($leftPath) ?: 0) - $left['dataOffset']);
            $rightActual = max(0, (filesize($rightPath) ?: 0) - $right['dataOffset']);
            $leftBytes = $left['dataSize'] > 0
                ? min($left['dataSize'], $leftActual)
                : $leftActual;
            $rightBytes = $right['dataSize'] > 0
                ? min($right['dataSize'], $rightActual)
                : $rightActual;

            // Sample-Count = min(beide), damit kein Mismatch am Ende.
            $sampleCountL = intdiv($leftBytes, $bytesPerSample);
            $sampleCountR = intdiv($rightBytes, $bytesPerSample);
            $sampleCount = min($sampleCountL, $sampleCountR);

            if ($sampleCount <= 0) {
                throw new RuntimeException(
                    'WAV-Merge: keine Audio-Samples in den Quelldateien '
                    . "(left bytes={$leftBytes}, right bytes={$rightBytes})."
                );
            }

            $dataSize = $sampleCount * $outChannels * $bytesPerSample;

            // RIFF Header (44 Byte) für PCM Stereo.
            $this->writeRiffHeader($outFp, $dataSize, $sampleRate, $bits, $outChannels, $byteRate, $blockAlign);

            // Interleaven in Chunks (4096 Samples pro Iteration).
            $chunkSamples = 4096;
            $remaining = $sampleCount;
            while ($remaining > 0) {
                $take = min($chunkSamples, $remaining);
                $lBuf = fread($lFp, $take * $bytesPerSample);
                $rBuf = fread($rFp, $take * $bytesPerSample);
                if ($lBuf === false || $rBuf === false) {
                    throw new RuntimeException('WAV-Merge: read error.');
                }
                // Pad if short read (sollte nicht passieren bei min()).
                $lBuf = str_pad($lBuf, $take * $bytesPerSample, "\0");
                $rBuf = str_pad($rBuf, $take * $bytesPerSample, "\0");

                $out = '';
                for ($i = 0; $i < $take; $i++) {
                    $offset = $i * $bytesPerSample;
                    $out .= substr($lBuf, $offset, $bytesPerSample);
                    $out .= substr($rBuf, $offset, $bytesPerSample);
                }
                fwrite($outFp, $out);
                $remaining -= $take;
            }
        } finally {
            @fclose($lFp);
            @fclose($rFp);
            @fclose($outFp);
        }
    }

    /**
     * Liest RIFF/fmt/data-Header eines unkomprimierten PCM-WAV.
     *
     * @return array{sampleRate:int,bitsPerSample:int,channels:int,dataOffset:int,dataSize:int}
     */
    private function readPcmWav(string $path): array
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            throw new RuntimeException("WAV-Merge: kann Datei nicht öffnen: {$path}");
        }
        try {
            $riff = fread($fp, 12);
            if ($riff === false || strlen($riff) < 12) {
                throw new RuntimeException("WAV-Merge: Datei zu kurz: {$path}");
            }
            if (substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
                throw new RuntimeException("WAV-Merge: kein RIFF/WAVE: {$path}");
            }

            $fmt = null;
            $dataOffset = null;
            $dataSize = null;

            while (!feof($fp)) {
                $header = fread($fp, 8);
                if ($header === false || strlen($header) < 8) {
                    break;
                }
                $chunkId = substr($header, 0, 4);
                $chunkSize = unpack('V', substr($header, 4, 4))[1] ?? 0;

                if ($chunkId === 'fmt ') {
                    $raw = fread($fp, $chunkSize);
                    if ($raw === false || strlen($raw) < 16) {
                        throw new RuntimeException("WAV-Merge: ungültiger fmt chunk: {$path}");
                    }
                    $u = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($raw, 0, 16));
                    $fmt = $u;
                    if ((int) ($u['audioFormat'] ?? 0) !== 1) {
                        throw new RuntimeException("WAV-Merge: nur unkomprimiertes PCM (audioFormat=1) unterstützt, gefunden {$u['audioFormat']}: {$path}");
                    }
                } elseif ($chunkId === 'data') {
                    $dataOffset = ftell($fp);
                    $dataSize = $chunkSize;
                    break; // genug — Rest streamt der Merger.
                } else {
                    // Unbekannten Chunk überspringen (Wort-aligned).
                    $skip = $chunkSize + ($chunkSize % 2);
                    fseek($fp, $skip, SEEK_CUR);
                }
            }

            if ($fmt === null || $dataOffset === null) {
                throw new RuntimeException("WAV-Merge: fmt/data chunk fehlt: {$path}");
            }

            return [
                'sampleRate' => (int) $fmt['sampleRate'],
                'bitsPerSample' => (int) $fmt['bitsPerSample'],
                'channels' => (int) $fmt['channels'],
                'dataOffset' => (int) $dataOffset,
                'dataSize' => (int) $dataSize,
            ];
        } finally {
            @fclose($fp);
        }
    }

    /**
     * Schreibt einen 44-Byte RIFF/PCM-Header.
     */
    private function writeRiffHeader(
        $fp,
        int $dataSize,
        int $sampleRate,
        int $bitsPerSample,
        int $channels,
        int $byteRate,
        int $blockAlign,
    ): void {
        $chunkSize = 36 + $dataSize;
        fwrite($fp, 'RIFF');
        fwrite($fp, pack('V', $chunkSize));
        fwrite($fp, 'WAVE');

        fwrite($fp, 'fmt ');
        fwrite($fp, pack('V', 16));               // fmt chunk size
        fwrite($fp, pack('v', 1));                // audio format = PCM
        fwrite($fp, pack('v', $channels));
        fwrite($fp, pack('V', $sampleRate));
        fwrite($fp, pack('V', $byteRate));
        fwrite($fp, pack('v', $blockAlign));
        fwrite($fp, pack('v', $bitsPerSample));

        fwrite($fp, 'data');
        fwrite($fp, pack('V', $dataSize));
    }
}
