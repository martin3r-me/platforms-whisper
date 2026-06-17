<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class WhisperOverviewTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'whisper.overview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /whisper/overview - Zeigt Uebersicht ueber das Whisper-Modul: Datenmodell, Import-Workflows, verfuegbare Tools. IMMER ZUERST aufrufen bevor andere Whisper-Tools genutzt werden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            return ToolResult::success([
                'module' => 'whisper',
                'scope' => [
                    'team_scoped' => true,
                    'team_id_source' => 'ToolContext.team bzw. team_id Parameter',
                ],
                'data_model' => [
                    'whisper_recordings' => [
                        'description' => 'Aufnahme mit Transkript und Sprecher-Segmenten. Kann via Browser-Recorder (AssemblyAI) oder externen Import erstellt werden. Hoehere Layer (Summary, Action Items, Q&A) liegen im Inbox-Modul.',
                        'key_fields' => [
                            'id', 'uuid', 'team_id', 'title', 'transcript',
                            'segments (JSON legacy)', 'speakers_count', 'speaker_map',
                            'language', 'duration_seconds', 'recorded_at',
                            'model', 'provider_id', 'device_serial', 'source_url',
                            'status', 'error_message',
                        ],
                        'status_funnel' => [
                            'pending' => 'Erstellt, wartet auf Verarbeitung.',
                            'processing' => 'Import/Transkription laeuft, Segmente werden angehaengt.',
                            'completed' => 'Fertig: Transkript und Segmente vorhanden.',
                            'failed' => 'Fehler. error_message enthaelt Details.',
                        ],
                    ],
                    'whisper_segments' => [
                        'description' => 'Einzelne Transkript-Segmente (Tabellenzeilen). Jedes Segment gehoert zu einer Recording und optional zu einem Speaker.',
                        'key_fields' => [
                            'whisper_recording_id', 'whisper_speaker_id (nullable)',
                            'speaker_label (A, B, C...)', 'text',
                            'start_seconds (float)', 'end_seconds (float)',
                            'embedding_key (Plaud Voice-UUID)', 'sort_order',
                        ],
                        'note' => 'Segmente werden parallel als JSON-Array in whisper_recordings.segments gespeichert (legacy) UND als Zeilen in whisper_segments (normalisiert).',
                    ],
                    'whisper_speakers' => [
                        'description' => 'Wiedererkennbare Sprecher pro Team. Werden ueber embedding_key (Plaud Voice-UUID) automatisch zugeordnet.',
                        'key_fields' => [
                            'id', 'uuid', 'team_id', 'name',
                            'embedding_key (unique pro Team)', 'source (plaud, manual)',
                        ],
                    ],
                ],
                'import_workflows' => [
                    'multi_step_import' => [
                        'step_1' => 'whisper.recordings.import.POST - Recording anlegen mit Metadaten',
                        'step_2' => 'whisper.recordings.segments.APPEND - Segmente in Batches (max ~50) anhaengen',
                        'step_3' => 'APPEND mit is_last_batch=true - Finalisiert Recording (baut Transcript, zaehlt Speaker)',
                    ],
                    'plaud' => 'Plaud-Import lebt jetzt im Inbox-Modul (inbox.plaud.sync.POST). Plaud-Items landen direkt im Inbox; Whisper bekommt keine Plaud-Recordings mehr.',
                ],
                'entity_linking' => [
                    'description' => 'Recordings koennen mit Organization-Entities (Projekt, Kunde, etc.) verknuepft werden.',
                    'morph_alias' => 'whisper_recording',
                    'how' => 'NICHT ueber metadata.entity_id! Stattdessen: organization.dimension_links.POST mit morph_alias "whisper_recording" und der Recording-ID.',
                    'speakers' => 'Speaker koennen ebenfalls verknuepft werden (morph_alias: whisper_speaker).',
                ],
                'segments_json_format' => [
                    'description' => 'Legacy JSON-Format in recordings.segments Spalte. Zeiteinheit: Sekunden.',
                    'item' => [
                        'speaker' => 'Label (A, B, C...)',
                        'text' => 'Gesprochener Text',
                        'start' => 'float, Sekunden',
                        'end' => 'float, Sekunden',
                        'embedding_key' => 'Plaud Voice-UUID (optional)',
                    ],
                ],
                'tools' => [
                    'import' => [
                        'whisper.plaud.sync.POST' => 'Plaud-Komplett-Import (empfohlen)',
                        'whisper.recordings.import.POST' => 'Generischer Import (Step 1: Recording anlegen)',
                        'whisper.recordings.segments.APPEND' => 'Segmente anhaengen (Step 2+3)',
                    ],
                    'read' => [
                        'whisper.recordings.GET' => 'Liste aller Recordings (Filter, Suche, Paginierung)',
                        'whisper.recording.GET' => 'Einzelne Recording mit allen Details',
                        'whisper.recording.transcript.GET' => 'Nur Transkript (fuer LLM-Verarbeitung)',
                        'whisper.recordings.search.GET' => 'Volltext-Suche in Titeln + Transkripten',
                    ],
                    'write' => [
                        'whisper.recordings.PUT' => 'Recording aktualisieren (Titel, Summary, etc.)',
                        'whisper.recordings.DELETE' => 'Recording loeschen (nicht reversibel)',
                    ],
                    'ai' => [
                        'whisper.recording.question.POST' => 'Frage an Transkript via LeMUR (nur bei AssemblyAI-Recordings mit provider_id)',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Whisper-Uebersicht: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'overview',
            'tags' => ['overview', 'help', 'whisper'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
