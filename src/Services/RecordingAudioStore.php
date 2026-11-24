<?php

namespace Platform\Whisper\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Whisper\Models\WhisperRecording;
use RuntimeException;
use Throwable;

/**
 * Das Original-Audio einer Aufnahme — die Grundlage, aus der Whisper jederzeit
 * (neu) transkribiert. Wird beim EINGANG über ContextFileService gesichert
 * (S3 in Produktion), nicht erst nach erfolgreicher Transkription: so geht
 * keine Aufnahme verloren, wenn der Transkriptions-Dienst ausfällt, und jede
 * Aufnahme lässt sich später mit einem besseren Modell neu transkribieren.
 */
class RecordingAudioStore
{
    /**
     * Sichert die lokale Datei als audio_original-Referenz der Aufnahme.
     * Idempotent: ist schon Audio gesichert, passiert nichts.
     *
     * @throws RuntimeException wenn das Sichern scheitert
     */
    public function persist(WhisperRecording $recording, string $path, string $persistedBy = 'intake'): void
    {
        if ($recording->audioReference()) {
            return;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Audio-Datei nicht gefunden: ' . basename($path));
        }
        if (!class_exists(\Platform\Core\Services\ContextFileService::class)) {
            throw new RuntimeException('Platform\\Core\\Services\\ContextFileService nicht geladen.');
        }

        $year = $recording->created_at?->format('Y') ?? date('Y');
        $month = $recording->created_at?->format('m') ?? date('m');
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        $mime = @mime_content_type($path) ?: 'application/octet-stream';

        // UploadedFile im Test-Modus: lässt den Service eine Server-Datei
        // annehmen, ohne den PHP-Upload-Check.
        $file = new UploadedFile($path, 'recording-' . $recording->uuid . '.' . $extension, $mime, null, true);

        $result = app(\Platform\Core\Services\ContextFileService::class)->uploadForContext(
            file: $file,
            contextType: WhisperRecording::class,
            contextId: $recording->id,
            options: [
                'folder' => "whisper/audio/{$recording->team_id}/{$year}/{$month}",
                'team_id' => $recording->team_id,
                'user_id' => $recording->created_by_user_id,
            ],
        );

        if (empty($result['id'])) {
            throw new RuntimeException('ContextFileService::uploadForContext lieferte keine id zurück.');
        }

        $recording->addFileReference(
            (int) $result['id'],
            ['kind' => 'audio_original', 'persisted_by' => $persistedBy],
        );
    }

    /**
     * Holt das gesicherte Audio in eine lokale Tmp-Datei (für die Transkription).
     * Der Aufrufer räumt die Datei auf. Null, wenn kein Audio gesichert ist.
     */
    public function materialize(WhisperRecording $recording): ?string
    {
        $file = $recording->audioReference()?->contextFile;
        if (!$file) {
            return null;
        }

        $tmp = self::tmpPath(pathinfo((string) $file->path, PATHINFO_EXTENSION) ?: 'bin');
        try {
            $in = Storage::disk($file->disk)->readStream($file->path);
            if (!$in) {
                return null;
            }
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }
        } catch (Throwable $e) {
            Log::warning('Whisper: gesichertes Audio nicht lesbar', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    /**
     * Lädt eine Audio-Datei von einer URL in eine Tmp-Datei — SOFORT, weil
     * signierte Anbieter-Links (Plaud) nach wenigen Minuten ablaufen.
     *
     * @throws RuntimeException bei HTTP-Fehler oder leerer Datei
     */
    public function download(string $url, string $extension = 'bin'): string
    {
        $tmp = self::tmpPath($extension);
        $res = \Illuminate\Support\Facades\Http::timeout(300)->sink($tmp)->get($url);
        if ($res->failed() || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new RuntimeException('Audio-Download fehlgeschlagen (HTTP ' . $res->status() . ').');
        }
        @chmod($tmp, 0644);

        return $tmp;
    }

    /** Persistenter Tmp-Pfad (überlebt den Request, der Job räumt auf). */
    public static function tmpPath(string $extension): string
    {
        $dir = storage_path('app/whisper-tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/' . Str::uuid() . '.' . ltrim(strtolower($extension), '.');
    }
}
