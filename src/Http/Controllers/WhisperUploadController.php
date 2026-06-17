<?php

namespace Platform\Whisper\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Platform\Whisper\Jobs\TranscribeRecordingJob;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\WavStereoMerger;
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
                'model' => 'assemblyai',
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

    /**
     * Dual-channel Upload: zwei Mono-WAVs (mic / loopback) → Stereo-Merge →
     * eine WhisperRecording → AssemblyAI im multichannel-Mode.
     *
     * Bearer-Auth via Passport (api.auth Middleware).
     * Beide Spuren müssen identisches PCM-Format haben.
     */
    public function storeDual(Request $request, WavStereoMerger $merger): JsonResponse
    {
        $validated = $request->validate([
            'mic' => 'required|file|max:512000',      // 500 MB
            'loopback' => 'required|file|max:512000', // 500 MB
            'recorded_at' => 'nullable|string',
            'title' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $team = $user->currentTeam;
        if (!$team) {
            return response()->json(['error' => 'No team context'], 422);
        }

        $tmpDir = storage_path('app/whisper-tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $micTmp = $tmpDir . '/' . Str::uuid() . '_mic.wav';
        $loopTmp = $tmpDir . '/' . Str::uuid() . '_loopback.wav';
        $stereoTmp = $tmpDir . '/' . Str::uuid() . '_stereo.wav';

        try {
            $this->moveUpload($request->file('mic')->getRealPath(), $micTmp);
            $this->moveUpload($request->file('loopback')->getRealPath(), $loopTmp);

            // Stereo-Merge (mic → L, loopback → R). Wirft bei Format-Mismatch.
            try {
                $merger->merge($micTmp, $loopTmp, $stereoTmp);
            } catch (Throwable $mergeError) {
                // Mono-WAVs aufräumen — kommen nicht durch.
                @unlink($micTmp);
                @unlink($loopTmp);
                @unlink($stereoTmp);
                return response()->json([
                    'error' => 'WAV-Merge fehlgeschlagen: ' . $mergeError->getMessage(),
                ], 422);
            }

            // Quellen löschen — Stereo-File reicht.
            @unlink($micTmp);
            @unlink($loopTmp);
            @chmod($stereoTmp, 0644);

            $recordedAt = $this->parseRecordedAt(
                $validated['recorded_at'] ?? null,
                $request->file('mic')->getClientOriginalName()
            );

            $title = $validated['title']
                ?? 'Aufnahme vom ' . ($recordedAt ?? now())->format('d.m.Y H:i');

            $sizeBytes = filesize($stereoTmp) ?: null;

            $recording = WhisperRecording::create([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'status' => WhisperRecording::STATUS_PENDING,
                'model' => 'assemblyai',
                'file_size_bytes' => $sizeBytes,
            ]);

            TranscribeRecordingJob::dispatch(
                $recording->id,
                $stereoTmp,
                'de',
                true, // multichannel
            );

            // 202 Accepted: Recording-Row existiert, aber Transkription läuft
            // erst async im Queue-Worker. Erst nach completed-Status sind
            // transcript/segments verfuegbar (siehe GET-Endpoint / Show-Page).
            return response()->json([
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'status' => $recording->status,
                'redirect' => route('whisper.recordings.show', ['recording' => $recording->id]),
            ], 202);
        } catch (Throwable $e) {
            @unlink($micTmp);
            @unlink($loopTmp);
            @unlink($stereoTmp);
            Log::error('Whisper dual upload failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function moveUpload(string $from, string $to): void
    {
        if (!@move_uploaded_file($from, $to)) {
            if (!@copy($from, $to)) {
                throw new \RuntimeException('Konnte Upload nicht im Tmp-Verzeichnis ablegen.');
            }
        }
        @chmod($to, 0644);
    }

    /**
     * Parst recorded_at aus ISO 8601 oder aus dem Mic-Dateinamen
     * ("<unix_ms>_mic.wav" oder "<unix_seconds>_mic.wav").
     */
    private function parseRecordedAt(?string $iso, ?string $micFilename): ?Carbon
    {
        if ($iso) {
            try {
                return Carbon::parse($iso);
            } catch (Throwable) {
                // ignore und versuch's mit dem Dateinamen
            }
        }

        if ($micFilename && preg_match('/^(\d{10,13})_mic\.wav$/i', $micFilename, $m)) {
            $ts = (int) $m[1];
            // 13-stellig = Millisekunden, 10-stellig = Sekunden.
            if (strlen($m[1]) === 13) {
                return Carbon::createFromTimestampMs($ts);
            }
            return Carbon::createFromTimestamp($ts);
        }

        return null;
    }
}
