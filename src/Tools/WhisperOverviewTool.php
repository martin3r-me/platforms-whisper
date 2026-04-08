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
        return 'GET /whisper/overview - Zeigt Uebersicht ueber das Whisper-Modul (Konzepte, Datenmodell, verfuegbare Tools). Whisper ist ein Audio-Transkriptions-Modul: Browser-Recorder -> OpenAI Whisper -> Transkript. Audio wird nicht persistiert.';
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
                'concepts' => [
                    'whisper_recordings' => [
                        'model' => 'Platform\\Whisper\\Models\\WhisperRecording',
                        'table' => 'whisper_recordings',
                        'key_fields' => [
                            'id', 'uuid', 'team_id', 'created_by_user_id',
                            'title', 'transcript', 'summary', 'language', 'duration_seconds',
                            'model', 'status', 'error_message',
                            'chunks_total', 'chunks_done', 'file_size_bytes',
                        ],
                        'note' => 'Audio-Aufnahme wird im Browser via MediaRecorder erstellt, an OpenAI Whisper transkribiert und nur das Transkript persistiert. Audio-Datei wird nach Verarbeitung verworfen. Nach der Transkription erzeugt ein LLM automatisch Titel und Kurz-Zusammenfassung (Bullet-Points).',
                    ],
                ],
                'status_funnel' => [
                    'pending' => 'Aufnahme hochgeladen, Job in Queue.',
                    'processing' => 'Job laeuft - ggf. Chunking + sequenzielle Whisper-Calls.',
                    'completed' => 'Transkript fertig.',
                    'failed' => 'Fehler waehrend Verarbeitung. error_message enthaelt Details.',
                ],
                'features' => [
                    'long_meetings' => 'Aufnahmen >20 MB werden serverseitig per ffmpeg in 10-Minuten-Chunks geteilt und sequenziell transkribiert. Progress via chunks_done/chunks_total.',
                    'queue_based' => 'Upload kehrt sofort zurueck, TranscribeRecordingJob verarbeitet im Hintergrund (timeout 1800s).',
                    'audio_discarded' => 'Audio-Datei wird nach Transkription geloescht. Nur Transkript bleibt persistent.',
                ],
                'organization_link' => [
                    'morph_alias' => 'whisper_recording',
                    'note' => 'Aufnahmen koennen mit Organization-Entities (Projekt, Kunde, Abteilung) verknuepft werden. Trait: HasOrganizationContexts. Eine Aufnahme kann nur EINE Entity haben. Verknuepfung erfolgt ueber die Tools des core/organization-Moduls (morph_alias: whisper_recording).',
                ],
                'related_tools' => [
                    'recordings' => [
                        'list' => 'whisper.recordings.GET',
                        'get' => 'whisper.recording.GET',
                        'update' => 'whisper.recordings.PUT',
                        'delete' => 'whisper.recordings.DELETE',
                        'search' => 'whisper.recordings.search.GET',
                        'transcript' => 'whisper.recording.transcript.GET',
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
