<?php

namespace Platform\Whisper\Tools;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\LocalTranscriptionService;
use Platform\Whisper\Services\RecordingAudioStore;
use Platform\Whisper\Services\RecordingIntakeService;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

/**
 * Plaud-Import in EINEM Call.
 *
 * Mit audio_url wird das Audio SOFORT geladen (Plauds signierte Links laufen nach
 * ~5 Minuten ab) und gesichert. Erkennt der eigene Server Sprecher (pyannote), geht
 * die Aufnahme durch den gemeinsamen Eingang (RecordingIntakeService) und Whisper
 * transkribiert selbst. Bis dahin wird Plauds Transkript übernommen — mit Audio,
 * damit später alles neu transkribiert werden kann. Plauds Notiz bleibt als
 * Quell-Material (source_note).
 *
 * Ohne audio_url: fertige (status=completed) WhisperRecording mit Plauds Transkript
 * + Segmenten + Speaker-Map. Der WhisperRecordingInboxObserver
 * sieht „created + completed" und ingestet die Aufnahme automatisch in den Eingang.
 *
 * whisper ist damit die EINE, quellen-agnostische Aufnahme-Schicht: Plaud (und
 * künftige Anbieter) werden WhisperRecordings. Segmente werden inline auf der
 * `segments`-Spalte abgelegt (collectSegments im Ingest-Job hat dafür einen
 * Fallback) — keine separaten Segment-Rows nötig.
 *
 * Dedup: source = "plaud", source_id = <file_id>.
 */
class PlaudSyncTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function getName(): string
    {
        return 'whisper.plaud.sync.POST';
    }

    public function getDescription(): string
    {
        return 'Importiert eine Plaud-Aufnahme als WhisperRecording und ingestet sie automatisch in den Eingang. '
            . 'Mit audio_url (presigned_url aus Plaud get_file, sofort übergeben — läuft nach Minuten ab) '
            . 'transkribiert Whisper das Audio selbst; ohne audio_url werden Plauds segments übernommen. '
            . 'Erwartet: file_id (Dedup), title, audio_url und/oder segments, metadata (start_at, duration_ms, serial_number).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID. Default: Kontext.'],
                'file_id' => ['type' => 'string', 'description' => 'Plaud file ID — Dedup-Key (source=plaud, source_id=<file_id>).'],
                'audio_url' => [
                    'type' => 'string',
                    'description' => 'presigned_url aus Plaud get_file. Wird sofort geladen; Whisper transkribiert selbst. Bevorzugt.',
                ],
                'title' => ['type' => 'string', 'description' => 'Titel der Aufnahme.'],
                'segments' => [
                    'type' => 'array',
                    'description' => 'Nur ohne audio_url nötig: Transcript-Utterances aus Plaud: {content, start_time (ms), end_time (ms), speaker, original_speaker}.',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'start_at (ISO/UTC), duration_ms, serial_number.',
                ],
                'note_content' => [
                    'type' => 'string',
                    'description' => 'Optional: Plauds eigene Notiz/Zusammenfassung als Markdown (aus get_note). '
                        . 'Wird als sekundäre Enrichment am Eingang-Item abgelegt — nicht neu erzeugt.',
                ],
                'language' => ['type' => 'string', 'description' => 'Optional: ISO-Sprachcode. Default: "de".'],
            ],
            'required' => ['file_id', 'title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $resolved = $this->resolveTeam($arguments, $context);
        if ($resolved['error']) {
            return $resolved['error'];
        }
        $teamId = (int) $resolved['team_id'];

        $fileId = trim((string) ($arguments['file_id'] ?? ''));
        $title = trim((string) ($arguments['title'] ?? ''));
        $audioUrl = trim((string) ($arguments['audio_url'] ?? ''));
        $rawSegments = $arguments['segments'] ?? [];
        $meta = $arguments['metadata'] ?? [];

        if ($fileId === '' || $title === '' || ($audioUrl === '' && empty($rawSegments))) {
            return ToolResult::error('VALIDATION_ERROR', 'file_id, title und audio_url oder segments sind erforderlich.');
        }

        $existing = WhisperRecording::findBySource($teamId, 'plaud', $fileId);
        if ($existing) {
            return ToolResult::success([
                'recording_id' => $existing->id,
                'id' => $existing->id,
                'duplicate' => true,
                'message' => "Aufnahme existiert bereits (#{$existing->id}).",
            ]);
        }

        // Plauds eigene Notiz/Zusammenfassung roh mitnehmen (Quell-Artefakt).
        // Wird von der Ingestion als sekundäre Enrichment am Item abgelegt.
        $noteContent = trim((string) ($arguments['note_content'] ?? ''));

        $audioPath = null;
        $audioError = null;
        if ($audioUrl !== '') {
            try {
                $audioPath = app(RecordingAudioStore::class)->download($audioUrl, $this->extensionFromUrl($audioUrl));
            } catch (\Throwable $e) {
                // Ohne Plaud-Segmente gibt es keinen Ersatz — dann scheitert der Import sichtbar.
                if (empty($rawSegments)) {
                    return ToolResult::error('AUDIO_DOWNLOAD_FAILED', 'Plaud-Audio nicht ladbar: ' . $e->getMessage());
                }
                $audioError = $e->getMessage();
            }
        }

        // Whisper transkribiert selbst, sobald der eigene Server Sprecher erkennt — oder wenn
        // Plaud kein Transkript liefert. Bis dahin ist Plauds Transkript (mit Sprechern und
        // Namen) besser als eines ohne Sprecher; das Audio wird trotzdem gesichert, damit
        // später alles mit whisper:retranscribe neu gerechnet werden kann.
        if ($audioPath !== null && (app(LocalTranscriptionService::class)->diarizes() || empty($rawSegments))) {
            ['recording' => $recording, 'duplicate' => $duplicate] = app(RecordingIntakeService::class)->intake([
                'team_id' => $teamId,
                'created_by_user_id' => $context->user?->id,
                'title' => $title,
                'source' => 'plaud',
                'source_id' => $fileId,
                'source_note' => $noteContent !== '' ? $noteContent : null,
                'device_serial' => isset($meta['serial_number']) ? trim((string) $meta['serial_number']) : null,
                'duration_seconds' => isset($meta['duration_ms']) ? (int) round(((int) $meta['duration_ms']) / 1000) : null,
                'recorded_at' => isset($meta['start_at']) ? $this->utc($meta['start_at']) : null,
                'language' => $arguments['language'] ?? 'de',
            ], $audioPath);

            return ToolResult::success([
                'recording_id' => $recording->id,
                'id' => $recording->id,
                'duplicate' => $duplicate,
                'transcription' => 'whisper',
                'message' => "WhisperRecording #{$recording->id} angelegt (Plaud-Audio) → Whisper transkribiert, dann Eingang.",
            ]);
        }

        [$transcript, $segments, $speakerMap] = $this->build($rawSegments);

        $recording = WhisperRecording::create([
            'team_id' => $teamId,
            'created_by_user_id' => $context->user?->id,
            'title' => $title,
            'transcript' => $transcript,
            'source_note' => $noteContent !== '' ? $noteContent : null,
            'segments' => $segments,
            'speaker_map' => $speakerMap,
            'speakers_count' => count($speakerMap),
            'language' => $arguments['language'] ?? 'de',
            'duration_seconds' => isset($meta['duration_ms']) ? (int) round(((int) $meta['duration_ms']) / 1000) : null,
            'model' => 'import:plaud',
            'source' => 'plaud',
            'source_id' => $fileId,
            'device_serial' => isset($meta['serial_number']) ? trim((string) $meta['serial_number']) : null,
            // Plaud-Timestamps sind UTC (naive Strings als UTC interpretieren).
            'recorded_at' => isset($meta['start_at']) ? $this->utc($meta['start_at']) : null,
            // Mit Audio erst processing: das Audio muss gesichert sein, BEVOR der Übergang auf
            // completed den Eingang auslöst (sonst landet das Item ohne Wiedergabe im Eingang).
            'status' => $audioPath !== null ? WhisperRecording::STATUS_PROCESSING : WhisperRecording::STATUS_COMPLETED,
            'error_message' => $audioError !== null ? mb_substr('Plaud-Audio nicht geladen, nur Plaud-Transkript: ' . $audioError, 0, 1000) : null,
        ]);

        if ($audioPath !== null) {
            try {
                app(RecordingAudioStore::class)->persist($recording, $audioPath, 'whisper.plaud.sync');
            } catch (\Throwable $e) {
                $recording->error_message = mb_substr('Audio-Persist: ' . $e->getMessage(), 0, 1000);
            } finally {
                @unlink($audioPath);
            }
            $recording->status = WhisperRecording::STATUS_COMPLETED;
            $recording->save();
        }
        // → WhisperRecordingInboxObserver (completed) dispatcht
        //   IngestRecordingToInboxJob → Eingang (inkl. inbox-nativem Enrichment).

        return ToolResult::success([
            'recording_id' => $recording->id,
            'id' => $recording->id,
            'duplicate' => false,
            'transcription' => 'plaud',
            'segments_count' => count($segments),
            'speakers_count' => count($speakerMap),
            'message' => "WhisperRecording #{$recording->id} angelegt (Plaud) → Eingang folgt.",
        ]);
    }

    /**
     * Plaud-Utterances → [transcript-Text, inline-Segmente, speaker_map].
     * Segment-Keys (start_seconds/end_seconds/speaker_label/text) passen zum
     * collectSegments-Fallback des Ingest-Jobs. Labels A,B,C,… je Sprecher.
     *
     * @param  array<int, array<string,mixed>>  $raw
     * @return array{0:string,1:array<int,array<string,mixed>>,2:array<string,string>}
     */
    protected function build(array $raw): array
    {
        $labelMap = [];
        $names = [];
        $idx = 0;
        $segments = [];
        $lines = [];

        foreach ($raw as $u) {
            $text = trim((string) ($u['content'] ?? $u['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $orig = trim((string) ($u['original_speaker'] ?? $u['speaker'] ?? ''));
            $name = trim((string) ($u['speaker'] ?? $orig));
            $key = $orig !== '' ? $orig : ($name !== '' ? $name : 'unknown');
            if (! isset($labelMap[$key])) {
                $labelMap[$key] = chr(65 + $idx);
                $idx++;
            }
            $label = $labelMap[$key];
            $names[$label] = $name !== '' ? $name : $label;

            $segments[] = [
                'start_seconds' => (int) round(((int) ($u['start_time'] ?? 0)) / 1000),
                'end_seconds' => (int) round(((int) ($u['end_time'] ?? 0)) / 1000),
                'speaker_label' => $label,
                'text' => $text,
            ];
            $lines[] = $names[$label] . ': ' . $text;
        }

        return [implode("\n\n", $lines), $segments, $names];
    }

    /** Dateiendung aus dem Pfad einer (signierten) URL, z. B. "ogg". */
    protected function extensionFromUrl(string $url): string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : 'ogg';
    }

    protected function utc(mixed $v): ?Carbon
    {
        try {
            return Carbon::parse($v, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['whisper', 'plaud', 'sync', 'import', 'audio'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
