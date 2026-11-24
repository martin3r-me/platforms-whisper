<?php

namespace Platform\Whisper\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Whisper\Services\RecordingAudioStore;
use Platform\Whisper\Services\RecordingIntakeService;
use Platform\Whisper\Services\WavStereoMerger;
use Throwable;

class WhisperUploadController extends Controller
{
    public function store(Request $request, RecordingIntakeService $intake): JsonResponse
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
            $tmpPath = RecordingAudioStore::tmpPath($file->getClientOriginalExtension() ?: 'webm');
            $this->moveUpload($file->getRealPath(), $tmpPath);

            ['recording' => $recording] = $intake->intake([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'source' => 'upload',
            ], $tmpPath);

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
     * Der allgemeine Audio-Eingang (Bearer-Auth) — für die eigene App und jede
     * weitere Quelle. Eine Datei (mono oder bereits stereo/dual), dazu woher sie
     * kommt: source + source_id machen den Upload idempotent (ein Retry nach
     * Netzabbruch legt keine zweite Aufnahme an).
     */
    public function storeAudio(Request $request, RecordingIntakeService $intake): JsonResponse
    {
        $validated = $request->validate([
            'audio' => 'required|file|max:1024000', // 1 GB
            'source' => 'required|string|max:50|regex:/^[a-z0-9_.-]+$/',
            'source_id' => 'nullable|string|max:191',
            'recorded_at' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
            'multichannel' => 'nullable|boolean',
            'device_serial' => 'nullable|string|max:100',
            'meeting_inbox_item_id' => 'nullable|integer',
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
            $tmpPath = RecordingAudioStore::tmpPath($file->getClientOriginalExtension() ?: 'bin');
            $this->moveUpload($file->getRealPath(), $tmpPath);

            ['recording' => $recording, 'duplicate' => $duplicate] = $intake->intake([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'title' => $validated['title'] ?? null,
                'source' => $validated['source'],
                'source_id' => $validated['source_id'] ?? null,
                'recorded_at' => $validated['recorded_at'] ?? null,
                'language' => $validated['language'] ?? 'de',
                'multichannel' => (bool) ($validated['multichannel'] ?? false),
                'device_serial' => $validated['device_serial'] ?? null,
                'target_inbox_item_id' => $this->resolveTargetInboxItemId(
                    $validated['meeting_inbox_item_id'] ?? null,
                    (int) $team->id
                ),
            ], $tmpPath);

            return response()->json([
                'id' => $recording->id,
                'uuid' => $recording->uuid,
                'status' => $recording->status,
                'duplicate' => $duplicate,
            ], $duplicate ? 200 : 202);
        } catch (Throwable $e) {
            Log::error('Whisper audio intake failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Dual-channel Upload: zwei Mono-WAVs (mic / loopback) → Stereo-Merge →
     * eine WhisperRecording → je Spur transkribiert (mic = Nutzer, loopback = Gegenseite).
     *
     * Bearer-Auth via Passport (api.auth Middleware).
     * Beide Spuren müssen identisches PCM-Format haben.
     */
    public function storeDual(Request $request, WavStereoMerger $merger, RecordingIntakeService $intake): JsonResponse
    {
        $validated = $request->validate([
            'mic' => 'required|file|max:512000',      // 500 MB
            'loopback' => 'required|file|max:512000', // 500 MB
            'recorded_at' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            // Optional: InboxItem id of a meeting this recording belongs
            // to. The Mac client fetches this from /api/inbox/meetings/
            // current. Server validates the item is accessible to the
            // calling user; mismatches degrade silently to standalone
            // (we don't want to fail an upload over a stale meeting id).
            'meeting_inbox_item_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $team = $user->currentTeam;
        if (!$team) {
            return response()->json(['error' => 'No team context'], 422);
        }

        $micTmp = RecordingAudioStore::tmpPath('wav');
        $loopTmp = RecordingAudioStore::tmpPath('wav');
        $stereoTmp = RecordingAudioStore::tmpPath('wav');

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

            ['recording' => $recording] = $intake->intake([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'title' => $validated['title'] ?? null,
                'source' => 'mac',
                'recorded_at' => $recordedAt,
                'multichannel' => true,
                'target_inbox_item_id' => $this->resolveTargetInboxItemId(
                    $validated['meeting_inbox_item_id'] ?? null,
                    (int) $team->id
                ),
            ], $stereoTmp);

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

    /**
     * Resolves the meeting_inbox_item_id sent by the client into a valid
     * InboxItem id within the user's team. Returns null silently for
     * missing / unreachable ids — we don't want a stale meeting hint to
     * fail the recording upload. The IngestRecordingToInboxJob handles
     * the not-found case again at handoff time as a second line of
     * defence.
     *
     * Queries via raw DB to avoid taking a hard module dependency on the
     * Inbox Eloquent model.
     */
    private function resolveTargetInboxItemId(?int $candidate, int $teamId): ?int
    {
        if (!$candidate || $candidate <= 0) {
            return null;
        }
        $row = DB::table('inbox_items')
            ->where('id', $candidate)
            ->where('team_id', $teamId)
            ->first(['id', 'channel']);
        if (!$row) {
            Log::info('Dual upload: meeting_inbox_item_id not found in team', [
                'candidate' => $candidate,
                'team_id' => $teamId,
            ]);
            return null;
        }
        return (int) $row->id;
    }
}
