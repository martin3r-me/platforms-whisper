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

    /** Referenz auf das gesicherte Original-Audio, falls vorhanden. */
    public function audioReference(): mixed
    {
        return $this->getOrderedFileReferences()
            ->first(fn ($r) => ($r->meta['kind'] ?? null) === 'audio_original');
    }
}
