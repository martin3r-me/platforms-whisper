<?php

namespace Platform\Whisper\Services;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Platform\Whisper\Jobs\TranscribeRecordingJob;
use Platform\Whisper\Models\WhisperRecording;
use Throwable;

/**
 * Der EINE Eingang für Aufnahmen — egal woher (Browser, Mac-Client, Datei-Upload,
 * Plaud, eigene App). Audio ist die Grundlage; Whisper transkribiert selbst.
 *
 *   1. Dedup über (team, source, source_id) — dieselbe Aufnahme kommt nie doppelt.
 *   2. Recording anlegen (pending).
 *   3. Audio SOFORT sichern (RecordingAudioStore) — vor der Transkription.
 *   4. TranscribeRecordingJob dispatchen; der Observer ingestet nach completed.
 *
 * Was eine Quelle zusätzlich mitliefert (z. B. Plauds Notiz), ist Quell-Material
 * (source_note) — Whisper verlässt sich nicht darauf.
 */
class RecordingIntakeService
{
    public function __construct(protected RecordingAudioStore $audio)
    {
    }

    /**
     * @param  array{
     *     team_id:int, created_by_user_id?:?int, title?:?string, source:string, source_id?:?string,
     *     recorded_at?:mixed, language?:?string, multichannel?:bool, device_serial?:?string,
     *     source_url?:?string, source_note?:?string, target_inbox_item_id?:?int, duration_seconds?:?int
     * }  $attrs
     * @param  string  $audioPath  lokale Tmp-Datei; wird vom Job aufgeräumt
     * @return array{recording: WhisperRecording, duplicate: bool}
     */
    public function intake(array $attrs, string $audioPath): array
    {
        $teamId = (int) $attrs['team_id'];
        $source = trim((string) $attrs['source']);
        $sourceId = isset($attrs['source_id']) ? trim((string) $attrs['source_id']) : '';

        if ($sourceId !== '' && ($existing = WhisperRecording::findBySource($teamId, $source, $sourceId))) {
            @unlink($audioPath);
            return ['recording' => $existing, 'duplicate' => true];
        }

        $recordedAt = $this->parseDate($attrs['recorded_at'] ?? null);

        try {
            $recording = WhisperRecording::create([
                'team_id' => $teamId,
                'created_by_user_id' => $attrs['created_by_user_id'] ?? null,
                'title' => ($attrs['title'] ?? null) ?: 'Aufnahme vom ' . ($recordedAt ?? now())->format('d.m.Y H:i'),
                'source' => $source,
                'source_id' => $sourceId !== '' ? $sourceId : null,
                'source_note' => ($attrs['source_note'] ?? null) ?: null,
                'source_url' => $attrs['source_url'] ?? null,
                'device_serial' => $attrs['device_serial'] ?? null,
                'target_inbox_item_id' => $attrs['target_inbox_item_id'] ?? null,
                'duration_seconds' => $attrs['duration_seconds'] ?? null,
                'language' => $attrs['language'] ?? 'de',
                'recorded_at' => $recordedAt,
                'file_size_bytes' => filesize($audioPath) ?: null,
                'model' => 'local',
                'status' => WhisperRecording::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Paralleler Eingang derselben Quelle (z. B. zwei Sync-Läufe) — der andere gewinnt.
            @unlink($audioPath);
            return ['recording' => WhisperRecording::findBySource($teamId, $source, $sourceId), 'duplicate' => true];
        }

        try {
            $this->audio->persist($recording, $audioPath);
        } catch (Throwable $e) {
            // Transkribiert wird trotzdem (aus der Tmp-Datei); der Job versucht das
            // Sichern nach der Transkription erneut und meldet es an der Aufnahme.
            \Log::warning('Whisper: Audio beim Eingang nicht gesichert', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);
        }

        TranscribeRecordingJob::dispatch(
            $recording->id,
            $audioPath,
            (string) ($attrs['language'] ?? 'de'),
            (bool) ($attrs['multichannel'] ?? false),
        );

        return ['recording' => $recording, 'duplicate' => false];
    }

    /**
     * Neu transkribieren aus dem gesicherten Audio (z. B. nach einem Fehler oder
     * mit einem besseren Modell). Null, wenn kein Audio gesichert ist.
     */
    public function retranscribe(WhisperRecording $recording, bool $multichannel = false): ?WhisperRecording
    {
        $path = $this->audio->materialize($recording);
        if ($path === null) {
            return null;
        }

        // pending → (Job) processing → completed; der Übergang auf completed löst den Eingang aus.
        $recording->update(['status' => WhisperRecording::STATUS_PENDING, 'error_message' => null]);
        TranscribeRecordingJob::dispatch($recording->id, $path, $recording->language ?: 'de', $multichannel);

        return $recording;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
