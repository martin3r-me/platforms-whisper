<?php

namespace Platform\Whisper\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lose Kopplung Aufnahme ↔ beliebiges Objekt (Morph-Alias + ID), z. B. gathering_meeting #7. */
class WhisperRecordingLink extends Model
{
    protected $table = 'whisper_recording_links';

    protected $fillable = ['recording_id', 'team_id', 'linkable_type', 'linkable_id', 'role', 'created_by'];

    protected $casts = ['linkable_id' => 'integer', 'recording_id' => 'integer', 'team_id' => 'integer'];

    public function recording(): BelongsTo
    {
        return $this->belongsTo(WhisperRecording::class, 'recording_id');
    }
}
