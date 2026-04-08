<?php

namespace Platform\Whisper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Organization\Traits\HasOrganizationContexts;
use Symfony\Component\Uid\UuidV7;

class WhisperRecording extends Model
{
    use HasOrganizationContexts;

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
        'language',
        'duration_seconds',
        'chunks_total',
        'chunks_done',
        'file_size_bytes',
        'model',
        'status',
        'error_message',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'chunks_total' => 'integer',
        'chunks_done' => 'integer',
        'file_size_bytes' => 'integer',
    ];

    public function progressPercent(): int
    {
        if (!$this->chunks_total || $this->chunks_total <= 0) {
            return $this->status === self::STATUS_COMPLETED ? 100 : 0;
        }
        return (int) round(($this->chunks_done / $this->chunks_total) * 100);
    }

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

    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
