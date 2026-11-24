<?php

namespace Platform\Whisper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Core\Traits\HasContextFileReferences;
use Symfony\Component\Uid\UuidV7;

class WhisperRecording extends Model
{
    use HasContextFileReferences;
    protected $table = 'whisper_recordings';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'team_id',
        'created_by_user_id',
        'title',
        'transcript',
        'source_note',
        'segments',
        'speakers_count',
        'speaker_map',
        'language',
        'duration_seconds',
        'file_size_bytes',
        'model',
        'source',
        'source_id',
        'provider_id',
        'device_serial',
        'source_url',
        'target_inbox_item_id',
        'status',
        'error_message',
        'recorded_at',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size_bytes' => 'integer',
        'speakers_count' => 'integer',
        'segments' => 'array',
        'speaker_map' => 'array',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    /** Woran die Aufnahme hängt (Morph-Alias + ID), siehe Services\RecordingLinks. */
    public function links(): HasMany
    {
        return $this->hasMany(WhisperRecordingLink::class, 'recording_id');
    }

    public function segmentRows(): HasMany
    {
        return $this->hasMany(WhisperSegment::class, 'whisper_recording_id');
    }

    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Die schon vorhandene Aufnahme derselben Quelle (Dedup). Fällt für Altdaten
     * vor der source-Spalte auf provider_id "<source>:<id>" zurück.
     */
    public static function findBySource(int $teamId, string $source, string $sourceId): ?self
    {
        return self::query()
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('source', $source)->where('source_id', $sourceId))
                ->orWhere('provider_id', $source . ':' . $sourceId))
            ->first();
    }

    /**
     * Segmente in EINEM Format, egal aus welcher Quelle: Segment-Rows (Import in Batches),
     * eigene Transkription ({speaker, start, end, text}) oder Plaud ({speaker_label,
     * start_seconds, end_seconds, text}).
     *
     * @return array<int, array{speaker: string, start: float, end: float, text: string}>
     */
    public function normalizedSegments(): array
    {
        $rows = $this->relationLoaded('segmentRows')
            ? $this->segmentRows->sortBy([['sort_order', 'asc'], ['start_seconds', 'asc']])
            : $this->segmentRows()->orderBy('sort_order')->orderBy('start_seconds')->get();
        $raw = $rows->isNotEmpty()
            ? $rows->map(fn ($s) => $s->getAttributes())->all()
            : (is_array($this->segments) ? $this->segments : []);

        $out = [];
        foreach ($raw as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'speaker' => (string) ($s['speaker'] ?? $s['speaker_label'] ?? 'A'),
                'start' => (float) ($s['start'] ?? $s['start_seconds'] ?? 0),
                'end' => (float) ($s['end'] ?? $s['end_seconds'] ?? 0),
                'text' => $text,
            ];
        }

        return $out;
    }

    /** Anzeigename eines Sprechers (speaker_map: "A" => "Name" oder ["name" => …]). */
    public function speakerName(string $label): ?string
    {
        $entry = (is_array($this->speaker_map) ? $this->speaker_map : [])[$label] ?? null;
        $name = is_array($entry) ? ($entry['name'] ?? $entry['display_name'] ?? null) : $entry;
        $name = is_string($name) ? trim($name) : '';

        return $name !== '' && $name !== $label ? $name : null;
    }

    /** @return array{label: string, icon: string} Anzeige der Quelle (Liste, Detail). */
    public function sourceDisplay(): array
    {
        return match ($this->source) {
            'plaud' => ['label' => 'Plaud', 'icon' => 'heroicon-o-microphone'],
            'mac' => ['label' => 'Mac', 'icon' => 'heroicon-o-computer-desktop'],
            'app' => ['label' => 'App', 'icon' => 'heroicon-o-device-phone-mobile'],
            'upload', null => ['label' => 'Browser', 'icon' => 'heroicon-o-globe-alt'],
            default => ['label' => ucfirst((string) $this->source), 'icon' => 'heroicon-o-arrow-down-tray'],
        };
    }

    /** @return array{label: string, variant: string}|null Status-Badge; null = fertig, kein Badge nötig. */
    public function statusDisplay(): ?array
    {
        return match ($this->status) {
            self::STATUS_PENDING => ['label' => 'Wartet', 'variant' => 'neutral'],
            self::STATUS_PROCESSING => ['label' => 'Wird transkribiert', 'variant' => 'info'],
            self::STATUS_FAILED => ['label' => 'Fehler', 'variant' => 'danger'],
            default => null,
        };
    }

    /** Zeitraum "10:59–11:48" aus Aufnahmezeit + Dauer. */
    public function timeRange(): string
    {
        $start = ($this->recorded_at ?? $this->created_at)->copy()->timezone(config('app.timezone'));
        if (!$this->duration_seconds) {
            return $start->format('H:i');
        }

        return $start->format('H:i') . '–' . $start->copy()->addSeconds($this->duration_seconds)->format('H:i');
    }

    /** Dauer lesbar: "48 Min", "1 Std 12 Min", "45 Sek". */
    public function durationLabel(): ?string
    {
        $s = (int) $this->duration_seconds;
        if ($s <= 0) {
            return null;
        }
        if ($s < 60) {
            return $s . ' Sek';
        }
        $m = intdiv($s + 30, 60);

        return $m < 60 ? $m . ' Min' : intdiv($m, 60) . ' Std' . ($m % 60 ? ' ' . ($m % 60) . ' Min' : '');
    }

    /** Referenz auf das gesicherte Original-Audio, falls vorhanden. */
    public function audioReference(): mixed
    {
        return $this->getOrderedFileReferences()
            ->first(fn ($r) => ($r->meta['kind'] ?? null) === 'audio_original');
    }
}
