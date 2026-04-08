<?php

namespace Platform\Whisper\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Whisper\Models\WhisperRecording;

class Sidebar extends Component
{
    #[On('recording-saved')]
    public function refresh(): void
    {
        // re-render
    }

    public function render()
    {
        $user = Auth::user();
        $teamId = $user?->currentTeam?->id;

        $recordings = collect();
        if ($teamId) {
            $recordings = WhisperRecording::query()
                ->where('team_id', $teamId)
                ->orderByDesc('created_at')
                ->limit(15)
                ->get();
        }

        return view('whisper::livewire.sidebar', [
            'recordings' => $recordings,
        ]);
    }
}
