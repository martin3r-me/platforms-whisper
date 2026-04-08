<?php

namespace Platform\Whisper\Livewire\Recording;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Whisper\Models\WhisperRecording;

class Show extends Component
{
    public WhisperRecording $recording;

    public function mount(WhisperRecording $recording): void
    {
        $user = Auth::user();
        $team = $user?->currentTeam;

        if (!$team || $recording->team_id !== $team->id) {
            abort(404);
        }

        $this->recording = $recording;
    }

    public function delete()
    {
        $this->recording->delete();
        return redirect()->route('whisper.dashboard');
    }

    public function render()
    {
        return view('whisper::livewire.recording.show', [
            'recording' => $this->recording,
        ])->layout('platform::layouts.app');
    }
}
