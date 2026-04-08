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
        // re-render
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

        $base = WhisperRecording::query()->where('team_id', $team->id);

        $recordings = (clone $base)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $stats = [
            'total' => (clone $base)->count(),
            'completed' => (clone $base)->where('status', WhisperRecording::STATUS_COMPLETED)->count(),
            'in_flight' => (clone $base)->whereIn('status', [
                WhisperRecording::STATUS_PENDING,
                WhisperRecording::STATUS_PROCESSING,
            ])->count(),
            'total_minutes' => (int) round(((clone $base)->sum('duration_seconds') ?? 0) / 60),
        ];

        return view('whisper::livewire.dashboard', [
            'recordings' => $recordings,
            'stats' => $stats,
        ])->layout('platform::layouts.app');
    }
}
