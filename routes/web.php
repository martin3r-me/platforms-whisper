<?php

use Platform\Whisper\Http\Controllers\WhisperUploadController;
use Platform\Whisper\Livewire\Dashboard;
use Platform\Whisper\Livewire\Recording\Show;

Route::get('/', Dashboard::class)->name('whisper.dashboard');
Route::get('/recordings/{recording}', Show::class)->name('whisper.recordings.show');
Route::post('/upload', [WhisperUploadController::class, 'store'])->name('whisper.upload');
