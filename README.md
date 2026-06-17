# Platform Whisper Module

Audio-Aufnahme → **AssemblyAI** Transkription mit Speaker Diarization (oder Dual-Channel Multichannel) → Transkript + Segmente + Sprecher landen persistent.

**Scope:** Whisper liefert Transkripte und Sprecher-Segmente. Höhere Layer (Zusammenfassungen, Action Items, Q&A, semantischer Titel) leben im **Inbox-Modul** als Enrichment-Pipeline. Whisper feuert nach `status=completed` einen Observer, der das Recording in den Inbox-Triage-Layer übergibt.

## Features

- Browser-Recorder via MediaRecorder API (Opus, mono)
- Dual-Channel-Upload (`POST /api/whisper/recordings/dual`, Bearer-Auth): zwei WAV-Spuren (`mic` + `loopback`) werden serverseitig zu Stereo gemerged und an AssemblyAI im Multichannel-Mode geschickt
- **Speaker Diarization** bei Mono / **Multichannel** bei Dual-Spur (Kanal-Identifier statt VAD-Ratespiel)
- **Queue-basiert**: Upload kehrt sofort zurück, Job verarbeitet im Hintergrund
- **Audio-Persistence**: Original-File wandert via `ContextFileService` auf S3, Recording behält File-Reference (`kind: audio_original`)
- **Inbox-Bridge**: Fertige Recordings werden automatisch als `InboxItem` mit Channel `recording` in die Inbox ingested
- **Organization-Linking**: `HasOrganizationContexts` (morph_alias `whisper_recording`)

## Voraussetzungen (Host-App)

### 1. Composer

```json
"require": {
    "martin3r/platforms-whisper": "dev-main"
},
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:martin3r-me/platforms-whisper.git"
    }
]
```

```bash
composer update martin3r/platforms-whisper
php artisan migrate
```

### 2. API Keys

```env
# Transkription + Diarization
ASSEMBLYAI_API_KEY=...
```

### 3. Queue Worker

```bash
php artisan queue:work --timeout=1800 --tries=1
```

Das Polling gegen AssemblyAI läuft während `handle()`. `--timeout=1800` deckt auch stundenlange Meetings ab.

### 4. PHP Limits

In `php.ini` (oder `.user.ini`):
```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
memory_limit = 256M
```

## Datenmodell

Tabelle `whisper_recordings` (Kern-Felder):

| Feld | Typ | Bemerkung |
|---|---|---|
| id / uuid | PK | UuidV7 |
| team_id / created_by_user_id | FK | Team-Scope |
| title | string | "Aufnahme vom dd.mm.yyyy HH:ii" (semantischer Titel kommt aus Inbox-Enrichment) |
| transcript | longText | Fließtext-Transkript |
| segments | json | `[{speaker, start, end, text}, …]` |
| speakers_count | int | Anzahl erkannter Sprecher |
| speaker_map | json | Optional: A/B/C → Anzeigename |
| language | string | ISO-Code, AssemblyAI-detected |
| duration_seconds | int | |
| model | string | z. B. `assemblyai:universal-3-pro` |
| provider_id | string | AssemblyAI transcript id |
| status | enum | pending / processing / completed / failed |
| error_message | text | bei failed |

## Workflow

1. Upload-Quelle:
   - Browser-Recorder auf `/whisper` → `POST /whisper/upload` (Session-Auth, mono Opus/WebM)
   - Dual-Channel Client → `POST /api/whisper/recordings/dual` (Bearer-Auth, zwei WAVs `mic` + `loopback`, serverseitig zu Stereo gemerged)
2. Controller speichert Audio in `storage/app/whisper-tmp/`, legt Recording mit `status=pending` an, dispatched `TranscribeRecordingJob`
3. Job (Worker):
   - Status → `processing`
   - `AssemblyAiTranscriptionService::transcribe()`: Upload → Submit → Polling bis `completed` (Mono: `speaker_labels`; Dual: `multichannel`)
   - Recording bekommt `transcript`, `segments`, `speakers_count`, `language`, `duration_seconds`, `provider_id`
   - Original-Audio wird via `ContextFileService` persistiert (File-Reference `kind: audio_original`)
   - Status → `completed` → Tmp-Datei wird gelöscht
4. `WhisperRecordingInboxObserver` feuert auf den completed-Übergang und dispatched `IngestRecordingToInboxJob` → Recording wandert als `InboxItem` in den Inbox-Triage-Layer
5. Show-Page pollt alle 3 s während `pending`/`processing`, zeigt Sprecher-Blöcke + Fließtext + Inline-Audio-Player

## Fehler-Handling

- Kein `ASSEMBLYAI_API_KEY` → Job wirft Exception → Status `failed`
- AssemblyAI-Fehler (Upload/Submit/Poll) → Status `failed`, `error_message` mit API-Response
- Polling-Timeout (`WHISPER_AAI_MAX_WAIT`) → Status `failed`
- Bei `failed`: Tmp-Datei wird trotzdem aufgeräumt

## LLM-Tools

- `whisper.overview.GET` — Modul-Übersicht
- `whisper.recordings.GET` — Liste aller Aufnahmen
- `whisper.recording.GET` — Einzel-Aufnahme (inkl. Segmente)
- `whisper.recordings.PUT` — Metadaten updaten
- `whisper.recordings.DELETE` — Aufnahme löschen
- `whisper.recordings.search.GET` — Volltextsuche
- `whisper.recording.transcript.GET` — Reines Transkript + Segments (LLM-freundlich)

## Config Overrides

```env
WHISPER_AAI_REQUEST_TIMEOUT=120     # HTTP-Timeout pro AssemblyAI-Call
WHISPER_AAI_POLL_INTERVAL=3         # Polling-Intervall in Sekunden
WHISPER_AAI_MAX_WAIT=1500           # Maximale Polling-Dauer
WHISPER_AAI_SPEAKER_LABELS=true     # Diarization an/aus
WHISPER_AAI_SPEAKERS_EXPECTED=0     # 0 = automatisch, sonst erwartete Anzahl
```

## Out-of-Scope

- Editierbares Transkript
- Echtzeit-Streaming
- Speaker-Identifikation (Zuordnung zu Personen/Namen) — liefert nur `A`, `B`, `C` … plus optionalen `speaker_map`-Override
- Zusammenfassung / Action Items / Q&A — lebt im Inbox-Modul als Enrichment-Pipeline
