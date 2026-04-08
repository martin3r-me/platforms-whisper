<?php

namespace Platform\Whisper\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\WhisperTranscriptionService;
use Throwable;

class WhisperUploadController extends Controller
{
    public function store(Request $request, WhisperTranscriptionService $service): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|max:51200', // 50 MB
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $team = $user->currentTeam;
        if (!$team) {
            return response()->json(['error' => 'No team context'], 422);
        }

        $recording = WhisperRecording::create([
            'team_id' => $team->id,
            'created_by_user_id' => $user->id,
            'title' => 'Aufnahme vom ' . now()->format('d.m.Y H:i'),
            'status' => WhisperRecording::STATUS_PROCESSING,
            'model' => 'whisper-1',
        ]);

        try {
            $file = $request->file('audio');
            $extension = $file->getClientOriginalExtension() ?: 'webm';
            $filename = 'audio.' . $extension;

            $result = $service->transcribe(
                $file->getRealPath(),
                $filename,
                'de',
                'whisper-1'
            );

            $recording->update([
                'transcript' => $result['transcript'],
                'language' => $result['language'],
                'duration_seconds' => $result['duration'] ? (int) round($result['duration']) : null,
                'model' => $result['model'],
                'status' => WhisperRecording::STATUS_COMPLETED,
            ]);

            return response()->json([
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'transcript' => $recording->transcript,
                'status' => $recording->status,
            ]);
        } catch (Throwable $e) {
            Log::error('Whisper transcription failed', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);

            $recording->update([
                'status' => WhisperRecording::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'id' => $recording->id,
                'status' => $recording->status,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
