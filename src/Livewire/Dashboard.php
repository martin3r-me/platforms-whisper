<?php

namespace Platform\Whisper\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Whisper\Models\WhisperRecording;

class Dashboard extends Component
{
    /** Quellen, die als Filter angeboten werden (Schlüssel = source-Spalte). */
    public const SOURCES = [
        'plaud' => ['label' => 'Plaud', 'icon' => 'heroicon-o-microphone'],
        'mac' => ['label' => 'Mac', 'icon' => 'heroicon-o-computer-desktop'],
        'upload' => ['label' => 'Browser & Upload', 'icon' => 'heroicon-o-arrow-up-tray'],
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $source = '';

    public int $limit = 50;

    #[On('recording-saved')]
    public function refreshList(): void
    {
        // re-render
    }

    public function updatedSearch(): void
    {
        $this->limit = 50;
    }

    public function setSource(string $source): void
    {
        $this->source = array_key_exists($source, self::SOURCES) ? $source : '';
        $this->limit = 50;
    }

    public function loadMore(): void
    {
        $this->limit += 50;
    }

    public function deleteRecording(int $id): void
    {
        $team = Auth::user()?->currentTeam;
        if (!$team) {
            return;
        }

        WhisperRecording::where('team_id', $team->id)->find($id)?->delete();
    }

    public function render()
    {
        $team = Auth::user()->currentTeam;
        $base = WhisperRecording::query()->where('team_id', $team->id);

        $query = (clone $base)
            ->when($this->source !== '', fn ($q) => $this->source === 'upload'
                // Browser-Aufnahmen und Datei-Uploads laufen beide als "upload"; Altdaten ohne Quelle auch.
                ? $q->where(fn ($q) => $q->where('source', 'upload')->orWhereNull('source'))
                : $q->where('source', $this->source))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($this->search)) . '%';
                $q->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('transcript', 'like', $term));
            })
            ->orderByRaw('COALESCE(recorded_at, created_at) DESC')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $recordings = $query->limit($this->limit)->get();

        $stats = [
            'total' => (clone $base)->count(),
            'hours' => round(((clone $base)->sum('duration_seconds') ?? 0) / 3600, 1),
            'in_flight' => (clone $base)->whereIn('status', [
                WhisperRecording::STATUS_PENDING,
                WhisperRecording::STATUS_PROCESSING,
            ])->count(),
        ];

        return view('whisper::livewire.dashboard', [
            'groups' => $this->groupByDay($recordings),
            'hasMore' => $total > $recordings->count(),
            'total' => $total,
            'stats' => $stats,
            'hasInFlight' => $recordings->whereIn('status', ['pending', 'processing'])->isNotEmpty(),
            'sources' => self::SOURCES,
            'filtered' => $this->source !== '' || trim($this->search) !== '',
        ])->layout('platform::layouts.app');
    }

    /** @return Collection<string, Collection<int, WhisperRecording>> Tages-Label → Aufnahmen */
    protected function groupByDay(Collection $recordings): Collection
    {
        return $recordings->groupBy(function (WhisperRecording $rec) {
            $day = ($rec->recorded_at ?? $rec->created_at)->copy()->timezone(config('app.timezone'))->startOfDay();
            if ($day->isToday()) {
                return 'Heute';
            }
            if ($day->isYesterday()) {
                return 'Gestern';
            }
            $format = $day->year === Carbon::now()->year ? 'dddd, D. MMMM' : 'dddd, D. MMMM YYYY';

            return $day->locale('de')->isoFormat($format);
        });
    }
}
