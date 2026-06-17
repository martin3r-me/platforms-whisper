<?php

use Platform\Whisper\Http\Controllers\WhisperUploadController;

Route::post('/recordings/dual', [WhisperUploadController::class, 'storeDual'])
    ->name('whisper.api.recordings.dual');
