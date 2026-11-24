<?php

use Platform\Whisper\Http\Controllers\WhisperUploadController;

// Allgemeiner Audio-Eingang (eigene App, weitere Quellen) — idempotent über source + source_id.
Route::post('/recordings', [WhisperUploadController::class, 'storeAudio'])
    ->name('whisper.api.recordings.store');

Route::post('/recordings/dual', [WhisperUploadController::class, 'storeDual'])
    ->name('whisper.api.recordings.dual');
