<?php

namespace Platform\Whisper\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Whisper\Models\WhisperRecording;

class Dashboard extends Component
{
    #[On('recording-saved')]
    public function refreshList(): void
    {
        // Just re-render
    }

    public function deleteRecording(int $id): void
    {
        $user = Auth::user();
        $team = $user?->currentTeam;
        if (!$team) {
            return;
        }

        $recording = WhisperRecording::where('team_id', $team->id)->find($id);
        if ($recording) {
            $recording->delete();
        }
    }

    public function render()
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        $recordings = WhisperRecording::query()
            ->where('team_id', $team->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('whisper::livewire.dashboard', [
            'recordings' => $recordings,
        ])->layout('platform::layouts.app');
    }
}
