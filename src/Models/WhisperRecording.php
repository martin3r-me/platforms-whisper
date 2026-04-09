<?php

namespace Platform\Whisper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'summary',
        'action_items',
        'segments',
        'speakers_count',
        'speaker_map',
        'language',
        'duration_seconds',
        'file_size_bytes',
        'model',
        'provider_id',
        'status',
        'error_message',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size_bytes' => 'integer',
        'speakers_count' => 'integer',
        'segments' => 'array',
        'speaker_map' => 'array',
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

    public function questions(): HasMany
    {
        return $this->hasMany(WhisperQuestion::class, 'whisper_recording_id');
    }

    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
