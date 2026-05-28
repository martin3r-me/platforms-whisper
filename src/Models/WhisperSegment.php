<?php

namespace Platform\Whisper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhisperSegment extends Model
{
    protected $table = 'whisper_segments';

    protected $fillable = [
        'whisper_recording_id',
        'whisper_speaker_id',
        'speaker_label',
        'text',
        'start_seconds',
        'end_seconds',
        'embedding_key',
        'sort_order',
    ];

    protected $casts = [
        'start_seconds' => 'float',
        'end_seconds' => 'float',
        'sort_order' => 'integer',
    ];

    public function recording(): BelongsTo
    {
        return $this->belongsTo(WhisperRecording::class, 'whisper_recording_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(WhisperSpeaker::class, 'whisper_speaker_id');
    }
}
