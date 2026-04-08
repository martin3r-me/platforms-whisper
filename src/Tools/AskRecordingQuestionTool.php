<?php

namespace Platform\Whisper\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\AssemblyAiLemurService;
use Platform\Whisper\Tools\Concerns\ResolvesWhisperTeam;

class AskRecordingQuestionTool implements ToolContract, ToolMetadataContract
{
    use ResolvesWhisperTeam;

    public function __construct(private AssemblyAiLemurService $lemur)
    {
    }

    public function getName(): string
    {
        return 'whisper.recording.question.POST';
    }

    public function getDescription(): string
    {
        return 'POST /whisper/recording/question - Stellt eine Frage an ein Transkript via AssemblyAI LeMUR (Claude/Anthropic). '
            . 'Gibt eine praezise Antwort basierend ausschliesslich auf dem Transkript-Inhalt zurueck. '
            . 'ERFORDERLICH: recording_id, question. Optional: context (Zusatzinfo/System-Hinweis).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'recording_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Aufnahme (ERFORDERLICH). Aufnahme muss Status "completed" haben.',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'Die Frage an das Transkript (ERFORDERLICH).',
                ],
                'context' => [
                    'type' => 'string',
                    'description' => 'Optional: Zusaetzlicher Kontext/Hinweis fuer das LLM.',
                ],
            ],
            'required' => ['recording_id', 'question'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            $recordingId = (int) ($arguments['recording_id'] ?? 0);
            $question = trim((string) ($arguments['question'] ?? ''));
            $contextText = isset($arguments['context']) ? trim((string) $arguments['context']) : null;

            if ($recordingId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'recording_id ist erforderlich.');
            }
            if ($question === '') {
                return ToolResult::error('VALIDATION_ERROR', 'question darf nicht leer sein.');
            }

            $rec = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->find($recordingId);

            if (!$rec) {
                return ToolResult::error('NOT_FOUND', 'Aufnahme nicht gefunden (oder kein Zugriff).');
            }

            if ($rec->status !== WhisperRecording::STATUS_COMPLETED) {
                return ToolResult::error('NOT_READY', "Transkript noch nicht verfuegbar (Status: {$rec->status}).");
            }

            if (empty($rec->provider_id)) {
                return ToolResult::error('NOT_AVAILABLE', 'Kein AssemblyAI provider_id vorhanden - Q&A nicht moeglich.');
            }

            $answer = $this->lemur->askQuestion(
                (string) $rec->provider_id,
                $question,
                $contextText ?: null,
                $rec->language ?: 'de'
            );

            if ($answer === null) {
                return ToolResult::error('LEMUR_ERROR', 'LeMUR lieferte keine Antwort. Siehe Logs fuer Details.');
            }

            return ToolResult::success([
                'recording_id' => $rec->id,
                'question' => $question,
                'answer' => $answer,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei LeMUR Q&A: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['whisper', 'transcript', 'lemur', 'qa'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
