<?php

namespace Platform\Whisper\Tools;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

/**
 * Plaud-Import in EINEM Call: legt eine fertige (status=completed) WhisperRecording
 * mit Transkript + Segmenten + Speaker-Map an. Plaud transkribiert selbst — es wird
 * NICHT neu transkribiert. Der WhisperRecordingInboxObserver sieht „created +
 * completed" und ingestet die Aufnahme automatisch in den Eingang.
 *
 * whisper ist damit die EINE, quellen-agnostische Aufnahme-Schicht: Plaud (und
 * künftige Anbieter) werden WhisperRecordings. Segmente werden inline auf der
 * `segments`-Spalte abgelegt (collectSegments im Ingest-Job hat dafür einen
 * Fallback) — keine separaten Segment-Rows nötig.
 *
 * Dedup: provider_id = "plaud:<file_id>".
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
        return 'Importiert eine komplette Plaud-Aufnahme in einem Call als fertige WhisperRecording '
            . '(Transkript + Segmente + Speaker). Wird automatisch in den Eingang ingestet. '
            . 'Erwartet: file_id (Dedup), title, segments (aus Plaud get_transcript), metadata (start_at, duration_ms, serial_number).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID. Default: Kontext.'],
                'file_id' => ['type' => 'string', 'description' => 'Plaud file ID — Dedup-Key (provider_id=plaud:<file_id>).'],
                'title' => ['type' => 'string', 'description' => 'Titel der Aufnahme.'],
                'segments' => [
                    'type' => 'array',
                    'description' => 'Transcript-Utterances aus Plaud: {content, start_time (ms), end_time (ms), speaker, original_speaker}.',
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
            'required' => ['file_id', 'title', 'segments'],
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
        $rawSegments = $arguments['segments'] ?? [];
        $meta = $arguments['metadata'] ?? [];

        if ($fileId === '' || $title === '' || empty($rawSegments)) {
            return ToolResult::error('VALIDATION_ERROR', 'file_id, title und segments sind erforderlich.');
        }

        $providerId = 'plaud:' . $fileId;

        $existing = WhisperRecording::query()
            ->where('team_id', $teamId)
            ->where('provider_id', $providerId)
            ->first();
        if ($existing) {
            return ToolResult::success([
                'recording_id' => $existing->id,
                'id' => $existing->id,
                'duplicate' => true,
                'message' => "Aufnahme existiert bereits (#{$existing->id}).",
            ]);
        }

        [$transcript, $segments, $speakerMap] = $this->build($rawSegments);

        // Plauds eigene Notiz/Zusammenfassung roh mitnehmen (Quell-Artefakt).
        // Wird von der Ingestion als sekundäre Enrichment am Item abgelegt.
        $noteContent = trim((string) ($arguments['note_content'] ?? ''));

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
            'provider_id' => $providerId,
            'device_serial' => isset($meta['serial_number']) ? trim((string) $meta['serial_number']) : null,
            // Plaud-Timestamps sind UTC (naive Strings als UTC interpretieren).
            'recorded_at' => isset($meta['start_at']) ? $this->utc($meta['start_at']) : null,
            'status' => WhisperRecording::STATUS_COMPLETED,
        ]);
        // → WhisperRecordingInboxObserver (created + completed) dispatcht
        //   IngestRecordingToInboxJob → Eingang (inkl. inbox-nativem Enrichment).

        return ToolResult::success([
            'recording_id' => $recording->id,
            'id' => $recording->id,
            'duplicate' => false,
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
