<?php

namespace Platform\Whisper\Livewire\Recording;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\Core\Livewire\Concerns\HasPageContext;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\RecordingIntakeService;
use Platform\Whisper\Services\RecordingLinks;

class Show extends Component
{
    use HasPageContext;

    /** Sprecherfarben (nx-Töne), stabil in der Reihenfolge des ersten Auftretens. */
    public const TONES = ['sky', 'emerald', 'violet', 'amber', 'rose', 'teal', 'indigo', 'pink'];

    public int $recordingId;

    public function mount(WhisperRecording $recording): void
    {
        $team = Auth::user()?->currentTeam;
        if (!$team || $recording->team_id !== $team->id) {
            abort(404);
        }

        $this->recordingId = $recording->id;
    }

    protected function pageContext(): ?Model
    {
        return $this->recording;
    }

    protected function pageContextOptions(): array
    {
        return [
            'url' => route('whisper.recordings.show', ['recording' => $this->recordingId]),
            'source' => 'whisper.recording.view',
        ];
    }

    public function getRecordingProperty(): ?WhisperRecording
    {
        return WhisperRecording::find($this->recordingId);
    }

    public function renameTitle(string $title): void
    {
        $title = trim($title);
        if ($title !== '') {
            $this->recording?->update(['title' => mb_substr($title, 0, 255)]);
        }
    }

    public function renameSpeaker(string $speakerKey, string $name): void
    {
        $speakerKey = trim($speakerKey);
        $recording = $this->recording;
        if ($speakerKey === '' || !$recording) {
            return;
        }

        $map = is_array($recording->speaker_map) ? $recording->speaker_map : [];
        $name = trim($name);
        if ($name === '') {
            unset($map[$speakerKey]);
        } else {
            $map[$speakerKey] = mb_substr($name, 0, 80);
        }

        $recording->update(['speaker_map' => $map !== [] ? $map : null]);
    }

    public function retranscribe(RecordingIntakeService $intake): void
    {
        $recording = $this->recording;
        if (!$recording || in_array($recording->status, [WhisperRecording::STATUS_PENDING, WhisperRecording::STATUS_PROCESSING], true)) {
            return;
        }
        $intake->retranscribe($recording, $recording->source === 'mac');
    }

    public function unlink(string $linkableType, int $linkableId, RecordingLinks $links): void
    {
        if ($recording = $this->recording) {
            $links->unlink($recording, $linkableType, $linkableId);
        }
    }

    public function delete()
    {
        $this->recording?->delete();

        return $this->redirectRoute('whisper.dashboard', navigate: true);
    }

    public function render()
    {
        $recording = $this->recording;
        if (!$recording) {
            abort(404);
        }

        $segments = $recording->normalizedSegments();

        // Sprecher in Reihenfolge des ersten Auftretens, mit Farbe und Redeanteil.
        $speakers = [];
        foreach ($segments as $seg) {
            $label = $seg['speaker'];
            $speakers[$label] ??= [
                'label' => $label,
                'name' => $recording->speakerName($label),
                'tone' => self::TONES[count($speakers) % count(self::TONES)],
                'seconds' => 0.0,
            ];
            $speakers[$label]['seconds'] += max(0, $seg['end'] - $seg['start']);
        }
        $talkTotal = array_sum(array_column($speakers, 'seconds')) ?: 1;
        foreach ($speakers as &$sp) {
            $sp['share'] = (int) round($sp['seconds'] / $talkTotal * 100);
        }
        unset($sp);

        $audio = collect($recording->getFileReferencesArray())
            ->first(fn ($f) => ($f['meta']['kind'] ?? null) === 'audio_original');

        return view('whisper::livewire.recording.show', [
            'recording' => $recording,
            'segments' => $segments,
            'speakers' => $speakers,
            'audioUrl' => $audio['url'] ?? null,
            'sourceNoteHtml' => $this->sourceNoteHtml($recording->source_note),
            'links' => $this->describedLinks($recording),
            'isInFlight' => in_array($recording->status, [WhisperRecording::STATUS_PENDING, WhisperRecording::STATUS_PROCESSING], true),
        ])->layout('platform::layouts.app');
    }

    /** @return array<int, array{type: string, label: string, linkable_type: string, linkable_id: int, role: ?string}> */
    protected function describedLinks(WhisperRecording $recording): array
    {
        $links = app(RecordingLinks::class);

        return $links->linksFor($recording)
            ->map(fn ($link) => ($d = $links->describe($link)) ? $d + [
                'linkable_type' => $link->linkable_type,
                'linkable_id' => $link->linkable_id,
                'role' => $link->role,
            ] : null)
            ->filter()
            ->values()
            ->all();
    }

    /** Notiz der Quelle (Plaud-Markdown) → HTML; Plaud-interne Links/Karten raus. */
    protected function sourceNoteHtml(?string $markdown): ?string
    {
        $markdown = trim((string) $markdown);
        if ($markdown === '') {
            return null;
        }
        $markdown = preg_replace('/\[[^\]]*\]\(plaud:\/\/[^)]*\)/', '', $markdown);

        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
