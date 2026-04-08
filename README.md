# Platform Whisper Module

Audio-Aufnahme im Browser → OpenAI Whisper Transkription → Persistierung des Transkripts.
Audio-Datei wird **nicht** dauerhaft gespeichert.

## Features

- Browser-Recorder via MediaRecorder API (Opus 24 kbit/s, mono, 16 kHz)
- **Lange Meetings**: serverseitiges Chunking via ffmpeg in 10-Minuten-Segmente
- **Queue-basiert**: Upload kehrt sofort zurück, Job verarbeitet im Hintergrund
- **Live-Progress**: Show-Page pollt alle 3s, Transkript baut sich Chunk für Chunk auf
- **Auto-Compression**: Audio > 10 MB wird vor Whisper-Call auf opus 24 kbit/s reduziert

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

### 2. ffmpeg + ffprobe

Müssen auf dem Host installiert sein:

```bash
# macOS
brew install ffmpeg

# Debian/Ubuntu
apt install ffmpeg
```

Falls nicht im PATH:
```env
WHISPER_FFMPEG_PATH=/usr/local/bin/ffmpeg
WHISPER_FFPROBE_PATH=/usr/local/bin/ffprobe
```

### 3. Queue Worker

```bash
php artisan queue:work --timeout=1800 --tries=1
```

`--timeout=1800` (30 min) ist Pflicht — kürzere Timeouts brechen lange Transkriptionen ab.

### 4. PHP Limits

In `php.ini` (oder `.user.ini`):
```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
memory_limit = 512M
```

### 5. OpenAI Key

```env
OPENAI_API_KEY=sk-...
```

## Tabellen

- `whisper_recordings` — Transkript + Status + Progress (chunks_total/done)

## Workflow

1. User klickt **Aufnehmen** auf `/whisper`
2. Browser nimmt Mic auf (24 kbit/s opus, mono)
3. Stop → Blob wird per `fetch()` an `/whisper/upload` POSTet
4. Controller speichert Blob in `storage/app/whisper-tmp/{uuid}.webm`, legt Recording mit `status=pending` an, dispatched `TranscribeRecordingJob`, redirected User zur Show-Page
5. Job (Worker):
   - Status → `processing`
   - ffprobe → Duration
   - Wenn > 20 MB → ffmpeg-Chunking in 10-Min-Segmente, sonst nur Compression
   - Pro Chunk: Whisper-Call → Transkript anhängen, `chunks_done++`
   - Status → `completed`, Tmp-Datei löschen
6. Show-Page pollt alle 3s während `pending`/`processing`, zeigt Progress-Bar, Transkript wächst live

## Fehler-Handling

- ffmpeg fehlt → Job wirft Exception → Status `failed`, Fehlermeldung im Recording
- Whisper-API-Fehler → Status `failed`
- Bei `failed`: Tmp-Datei wird trotzdem aufgeräumt (`finally` block)

## Out-of-Scope

- Editierbares Transkript
- Speaker-Diarization
- Echtzeit-Streaming
- LLM-Tools (Phase 3)
