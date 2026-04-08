<?php

namespace Platform\Whisper\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Platform\Whisper\Jobs\TranscribeRecordingJob;
use Platform\Whisper\Models\WhisperRecording;
use Throwable;

class WhisperUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|max:512000', // 500 MB
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $team = $user->currentTeam;
        if (!$team) {
            return response()->json(['error' => 'No team context'], 422);
        }

        try {
            $file = $request->file('audio');
            $extension = strtolower($file->getClientOriginalExtension() ?: 'webm');

            // Persistente Tmp-Datei (überlebt den Request, wird vom Job aufgeräumt)
            $tmpDir = storage_path('app/whisper-tmp');
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0755, true);
            }

            $tmpName = (string) Str::uuid() . '.' . $extension;
            $tmpPath = $tmpDir . '/' . $tmpName;

            if (!@move_uploaded_file($file->getRealPath(), $tmpPath)) {
                // Fallback (z.B. bei symlinked tmp)
                if (!@copy($file->getRealPath(), $tmpPath)) {
                    throw new \RuntimeException('Konnte Audio-Datei nicht im Tmp-Verzeichnis ablegen.');
                }
            }
            @chmod($tmpPath, 0644);

            $sizeBytes = filesize($tmpPath) ?: null;

            $recording = WhisperRecording::create([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'title' => 'Aufnahme vom ' . now()->format('d.m.Y H:i'),
                'status' => WhisperRecording::STATUS_PENDING,
                'model' => 'whisper-1',
                'file_size_bytes' => $sizeBytes,
            ]);

            TranscribeRecordingJob::dispatch($recording->id, $tmpPath, 'de');

            return response()->json([
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'status' => $recording->status,
                'redirect' => route('whisper.recordings.show', ['recording' => $recording->id]),
            ]);
        } catch (Throwable $e) {
            Log::error('Whisper upload failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
