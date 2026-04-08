<?php

namespace Platform\Whisper\Livewire\Recording;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Whisper\Models\WhisperRecording;

class Show extends Component
{
    public int $recordingId;

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
            // Leer = Mapping entfernen (Fallback auf Original-Key A/B/C)
            unset($map[$speakerKey]);
        } else {
            $map[$speakerKey] = mb_substr($name, 0, 80);
        }

        $recording->update(['speaker_map' => $map !== [] ? $map : null]);
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

        return view('whisper::livewire.recording.show', [
            'recording' => $recording,
        ])->layout('platform::layouts.app');
    }
}
