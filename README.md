# Platform Whisper Module

Audio-Aufnahme im Browser → **AssemblyAI** Transkription mit Speaker Diarization → LLM-Zusammenfassung.
Audio-Datei wird **nicht** dauerhaft gespeichert, nur Transkript + Segmente + Summary bleiben persistent.

## Features

- Browser-Recorder via MediaRecorder API (Opus, mono)
- **Speaker Diarization**: AssemblyAI markiert Sprecher (A, B, C, …) pro Äußerung
- **LLM-Summary**: automatischer Titel + Bullet-Point-Zusammenfassung via OpenAI
- **Queue-basiert**: Upload kehrt sofort zurück, Job verarbeitet im Hintergrund
- **Organization-Linking**: `HasOrganizationContexts` (morph_alias `whisper_recording`), nutzbar über die Core-LLM-Tools

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

# LLM-Summary (wiederverwendet OpenAiService der Platform)
OPENAI_API_KEY=sk-...
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
| title | string | LLM-generiert (Fallback: erster Satz) |
| transcript | longText | Fließtext-Transkript |
| summary | longText | LLM-Bullet-Points |
| segments | json | `[{speaker, start, end, text}, …]` |
| speakers_count | int | Anzahl erkannter Sprecher |
| language | string | ISO-Code, AssemblyAI-detected |
| duration_seconds | int | |
| model | string | z. B. `assemblyai:universal` |
| provider_id | string | AssemblyAI transcript id |
| status | enum | pending / processing / completed / failed |
| error_message | text | bei failed |

## Workflow

1. User klickt **Aufnehmen** auf `/whisper`
2. Browser nimmt Mic auf (Opus, mono)
3. Stop → Blob wird per `fetch()` an `/whisper/upload` POSTet
4. Controller speichert Blob in `storage/app/whisper-tmp/{uuid}.webm`, legt Recording mit `status=pending` an, dispatched `TranscribeRecordingJob`, redirected User zur Show-Page
5. Job (Worker):
   - Status → `processing`
   - `AssemblyAiTranscriptionService::transcribe()`: Upload → Submit (mit `speaker_labels=true`) → Polling bis `completed`
   - `WhisperSummaryService::summarize()`: LLM erzeugt Titel + Summary
   - Recording bekommt `transcript`, `segments`, `speakers_count`, `summary`, `title`, Status → `completed`
   - Tmp-Datei wird gelöscht (`finally`-Block)
6. Show-Page pollt alle 3 s während `pending`/`processing`, zeigt danach Sprecher-Blöcke + Summary + Fließtext

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
- `whisper.recording.transcript.GET` — Nur Transkript + Summary + Segments (LLM-freundlich)

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
- Speaker-Identifikation (Zuordnung zu Personen/Namen) — liefert nur `A`, `B`, `C` …
