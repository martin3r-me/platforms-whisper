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
        return 'GET /whisper/overview - Zeigt Uebersicht ueber das Whisper-Modul (Konzepte, Datenmodell, verfuegbare Tools). Whisper ist ein Audio-Transkriptions-Modul: Browser-Recorder -> AssemblyAI (Transcript + Speaker Diarization) -> LeMUR (Titel + Summary + Action Items + Q&A). Audio wird nicht persistiert.';
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
                            'title', 'transcript', 'summary', 'action_items',
                            'segments', 'speakers_count', 'speaker_map',
                            'language', 'duration_seconds',
                            'model', 'provider_id', 'status', 'error_message',
                            'file_size_bytes',
                        ],
                        'note' => 'Audio-Aufnahme wird im Browser via MediaRecorder erstellt, an AssemblyAI gesendet (Transcription + Speaker Diarization). Anschliessend laeuft AssemblyAI LeMUR (Claude/Anthropic) ueber dem Transkript und generiert Titel, Summary und Action Items. Audio-Datei wird nach Verarbeitung verworfen. provider_id = AssemblyAI transcript id (wird fuer LeMUR-Calls weiterverwendet).',
                    ],
                ],
                'status_funnel' => [
                    'pending' => 'Aufnahme hochgeladen, Job in Queue.',
                    'processing' => 'Upload zu AssemblyAI, Polling bis fertig.',
                    'completed' => 'Transkript fertig, inkl. Sprecher-Segmente und Summary.',
                    'failed' => 'Fehler waehrend Verarbeitung. error_message enthaelt Details.',
                ],
                'features' => [
                    'speaker_diarization' => 'AssemblyAI liefert speaker_labels; Segmente mit speaker/start/end/text landen in Spalte segments. speakers_count zaehlt die erkannten Sprecher. speaker_map erlaubt manuelles Benennen der Sprecher in der UI.',
                    'lemur_insights' => 'Nach Transkription ruft AssemblyAiLemurService /lemur/v3/generate/task auf und generiert in einem einzigen Call Titel + Summary + Action Items (Claude/Anthropic unter der Haube).',
                    'lemur_qa' => 'Ueber whisper.recording.question.POST kann eine beliebige Frage an das Transkript gestellt werden (LeMUR Q&A, nutzt provider_id als transcript_id).',
                    'queue_based' => 'Upload kehrt sofort zurueck, TranscribeRecordingJob verarbeitet im Hintergrund (timeout 1800s).',
                    'audio_discarded' => 'Audio-Datei wird nach Transkription geloescht. Nur Transkript + Segmente + Insights bleiben persistent.',
                ],
                'segments_schema' => [
                    'type' => 'array<object>',
                    'item' => [
                        'speaker' => 'A, B, C... (AssemblyAI labels)',
                        'start' => 'float seconds',
                        'end' => 'float seconds',
                        'text' => 'string',
                    ],
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
                        'question' => 'whisper.recording.question.POST',
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
