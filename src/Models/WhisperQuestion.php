<?php

namespace Platform\Whisper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class WhisperQuestion extends Model
{
    protected $table = 'whisper_questions';

    protected $fillable = [
        'uuid',
        'whisper_recording_id',
        'team_id',
        'created_by_user_id',
        'question',
        'answer',
        'status',
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

    public function recording(): BelongsTo
    {
        return $this->belongsTo(WhisperRecording::class, 'whisper_recording_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }
}
