<?php

namespace Platform\Whisper\Livewire\Recording;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Whisper\Models\WhisperQuestion;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\AssemblyAiLemurService;

class Show extends Component
{
    public int $recordingId;
    public string $questionInput = '';
    public bool $askingQuestion = false;

    public function mount(WhisperRecording $recording): void
    {
        $user = Auth::user();
        $team = $user?->currentTeam;

        if (!$team || $recording->team_id !== $team->id) {
            abort(404);
        }

        $this->recordingId = $recording->id;
    }

    public function delete()
    {
        $recording = WhisperRecording::find($this->recordingId);
        if ($recording) {
            $recording->delete();
        }
        return redirect()->route('whisper.dashboard');
    }

    public function renameSpeaker(string $speakerKey, string $name): void
    {
        $speakerKey = trim($speakerKey);
        $name = trim($name);

        if ($speakerKey === '') {
            return;
        }

        $recording = $this->recording;
        if (!$recording) {
            return;
        }

        $map = is_array($recording->speaker_map) ? $recording->speaker_map : [];

        if ($name === '') {
            unset($map[$speakerKey]);
        } else {
            $map[$speakerKey] = mb_substr($name, 0, 80);
        }

        $recording->update(['speaker_map' => $map !== [] ? $map : null]);
    }

    public function askQuestion(): void
    {
        $question = trim($this->questionInput);
        if ($question === '') {
            return;
        }

        $recording = $this->recording;
        if (!$recording || $recording->status !== WhisperRecording::STATUS_COMPLETED) {
            return;
        }

        if (empty($recording->provider_id)) {
            return;
        }

        $user = Auth::user();
        if (!$user) {
            return;
        }

        $this->askingQuestion = true;
        $this->questionInput = '';

        try {
            /** @var AssemblyAiLemurService $lemur */
            $lemur = resolve(AssemblyAiLemurService::class);

            $answer = $lemur->askQuestion(
                (string) $recording->provider_id,
                $question,
                null,
                $recording->language ?: 'de'
            );

            WhisperQuestion::create([
                'whisper_recording_id' => $recording->id,
                'team_id' => $recording->team_id,
                'created_by_user_id' => $user->id,
                'question' => $question,
                'answer' => $answer ?: 'Keine Antwort erhalten.',
                'status' => $answer ? 'completed' : 'failed',
            ]);
        } catch (\Throwable $e) {
            WhisperQuestion::create([
                'whisper_recording_id' => $recording->id,
                'team_id' => $recording->team_id,
                'created_by_user_id' => $user->id,
                'question' => $question,
                'answer' => 'Fehler: ' . mb_substr($e->getMessage(), 0, 500),
                'status' => 'failed',
            ]);
        } finally {
            $this->askingQuestion = false;
        }
    }

    public function deleteQuestion(int $questionId): void
    {
        $recording = $this->recording;
        if (!$recording) {
            return;
        }

        WhisperQuestion::query()
            ->where('whisper_recording_id', $recording->id)
            ->where('id', $questionId)
            ->delete();
    }

    public function getRecordingProperty(): ?WhisperRecording
    {
        return WhisperRecording::find($this->recordingId);
    }

    public function render()
    {
        $recording = $this->recording;

        if (!$recording) {
            abort(404);
        }

        $questions = WhisperQuestion::query()
            ->where('whisper_recording_id', $recording->id)
            ->orderBy('created_at', 'asc')
            ->get();

        return view('whisper::livewire.recording.show', [
            'recording' => $recording,
            'questions' => $questions,
        ])->layout('platform::layouts.app');
    }
}
