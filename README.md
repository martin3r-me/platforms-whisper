# Platform Whisper Module

Audio aus jeder Quelle → Transkription auf dem **eigenen Server** (Whisper + optional pyannote für Sprecher; Dual-Spur je Kanal) → Transkript + Segmente + Sprecher landen persistent. Keine Fremdleistung.

**Scope:** Whisper liefert Transkripte und Sprecher-Segmente. Höhere Layer (Zusammenfassungen, Action Items, Q&A, semantischer Titel) leben im **Inbox-Modul** als Enrichment-Pipeline. Whisper feuert nach `status=completed` einen Observer, der das Recording in den Inbox-Triage-Layer übergibt.

## Features

- **Ein Eingang für alle Quellen** (`RecordingIntakeService`): Browser, Mac-Client, Datei-Upload, Plaud, eigene App. Audio ist die Grundlage — es wird beim Eingang gesichert, dann transkribiert Whisper selbst. Dedup über `source` + `source_id`
- Allgemeiner Audio-Eingang `POST /api/whisper/recordings` (Bearer-Auth): `audio`, `source`, `source_id`, optional `recorded_at`, `title`, `language`, `multichannel`, `device_serial`, `meeting_inbox_item_id`
- Plaud: `user-connectors:plaud-sync` (alle 15 Min.) übergibt Plauds Audio-Link an `whisper.plaud.sync`; Plauds Notiz bleibt als `source_note` zum Vergleich
- Neu transkribieren aus dem gesicherten Audio: `php artisan whisper:retranscribe --id=<id>` oder `--failed`
- Browser-Recorder via MediaRecorder API (Opus, mono)
- Dual-Channel-Upload (`POST /api/whisper/recordings/dual`, Bearer-Auth): zwei WAV-Spuren (`mic` + `loopback`) werden zu Stereo gemerged gesichert und je Spur transkribiert (mic = `A`, Gegenseite = `B`, mit pyannote weiter aufgeteilt)
- **Sprechererkennung** bei Mono über pyannote (optional) — Whisper-Wörter werden den pyannote-Zeitfenstern zugeordnet
- Plaud: solange keine Sprechererkennung eingerichtet ist, wird Plauds Transkript (mit Sprechern/Namen) übernommen, das Audio aber gesichert
- **Queue-basiert**: Upload kehrt sofort zurück, Job verarbeitet im Hintergrund
- **Audio-Persistence**: Original-File wandert via `ContextFileService` auf S3, Recording behält File-Reference (`kind: audio_original`)
- **Inbox-Bridge**: Fertige Recordings werden automatisch als `InboxItem` mit Channel `recording` in die Inbox ingested
- **Organization-Linking**: über `organization.dimension_links` (morph_alias `whisper_recording`)

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

### 2. Transkriptions-Server

Kurzform für die eigene GPU-Box (Gate mit Bearer-Schlüssel): Whisper large-v3, pyannote und alle Pfade ergeben sich daraus.

```env
GPU_GATE_URL=http://<gpu-gate-host>:<port>
GPU_GATE_KEY=...   # Bearer-Schlüssel des Gates (beim Betrieb der GPU-Box erfragen)
```

Bevorzugt wird `POST {GPU_GATE_URL}/v1/audio/transcriptions/speakers` (Text mit Sprechern in einem Aufruf). Liefert der Pfad keine Sprecher je Segment, ordnet Whisper selbst zu (`/v1/audio/transcriptions` + `/v1/audio/diarize`).

Einzeln überschreibbar:

```env
# OpenAI-kompatibler Whisper-Server (POST {url}/v1/audio/transcriptions) — z. B. derselbe wie Parlan.
# Fällt auf PARLAN_STT_URL zurück. Leer = keine Transkription (Aufnahmen werden gesichert, Status failed).
WHISPER_STT_URL=http://stt.intern:8000
WHISPER_STT_MODEL=whisper-1

# Optional: Sprechererkennung (pyannote/speaker-diarization-community-1), volle URL
WHISPER_DIARIZATION_URL=http://stt.intern:8001/diarize
```

**Vertrag Sprechererkennung:** `POST {WHISPER_DIARIZATION_URL}`, multipart `file` (optional `num_speakers`), Antwort:

```json
{
  "segments": [{"start": 0.5, "end": 3.2, "speaker": "SPEAKER_00"}],
  "exclusive_segments": [{"start": 0.5, "end": 3.2, "speaker": "SPEAKER_00"}]
}
```

`exclusive_segments` (pyannote community-1: höchstens ein Sprecher gleichzeitig) ist optional und wird bevorzugt. Der Whisper-Server sollte `response_format=verbose_json` mit `timestamp_granularities[]=word` unterstützen (Wort-Zeitstempel → saubere Sprecherwechsel); ohne Wörter wird auf Segment-Ebene zugeordnet.

### 3. Queue Worker

```bash
php artisan queue:work --timeout=3600 --tries=1
```

Die Transkription läuft synchron gegen den eigenen Server während `handle()`; Dual-Spur sind zwei Durchläufe.

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
| language | string | ISO-Code (vom Whisper-Server erkannt) |
| duration_seconds | int | |
| model | string | z. B. `local:whisper-1+pyannote`, `import:plaud` |
| source / source_id | string | Quelle + ID dort (`plaud` / File-ID, `mac`, `upload`, …) — Dedup |
| status | enum | pending / processing / completed / failed |
| error_message | text | bei failed |

## Workflow

1. Upload-Quelle:
   - Browser-Recorder auf `/whisper` → `POST /whisper/upload` (Session-Auth, mono Opus/WebM)
   - Dual-Channel Client → `POST /api/whisper/recordings/dual` (Bearer-Auth, zwei WAVs `mic` + `loopback`, serverseitig zu Stereo gemerged)
   - Plaud → `user-connectors:plaud-sync` → `whisper.plaud.sync`
   - Eigene App / weitere Quellen → `POST /api/whisper/recordings`
2. `RecordingIntakeService`: Dedup (`source` + `source_id`), Recording `status=pending`, Original-Audio sofort via `ContextFileService` gesichert (`kind: audio_original`), dispatched `TranscribeRecordingJob`
3. Job (Worker):
   - Status → `processing`
   - `LocalTranscriptionService::transcribe()`: Whisper (Wörter + Zeitstempel), optional pyannote (Sprecher), Zuordnung; Dual-Spur je Kanal
   - Recording bekommt `transcript`, `segments`, `speakers_count`, `language`, `duration_seconds`
   - Status → `completed` → Tmp-Datei wird gelöscht
4. `WhisperRecordingInboxObserver` feuert auf den completed-Übergang und dispatched `IngestRecordingToInboxJob` → Recording wandert als `InboxItem` in den Inbox-Triage-Layer
5. Show-Page pollt alle 3 s während `pending`/`processing`, zeigt Sprecher-Blöcke + Fließtext + Inline-Audio-Player

## Fehler-Handling

- Kein `WHISPER_STT_URL` → Status `failed` („Keine Transkription eingerichtet")
- Fehler des Whisper- oder pyannote-Servers → Status `failed`, `error_message` mit Antwort
- Das Audio ist gesichert → nach Behebung `php artisan whisper:retranscribe --failed`

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
WHISPER_STT_API_KEY=                # optional, falls der Whisper-Server einen verlangt
WHISPER_DIARIZATION_API_KEY=        # optional, falls der pyannote-Dienst einen verlangt
WHISPER_STT_TIMEOUT=1800            # HTTP-Timeout je Aufruf (Sekunden)
WHISPER_SPEAKERS_EXPECTED=0         # 0 = automatisch, sonst erwartete Anzahl Sprecher
```

## Out-of-Scope

- Editierbares Transkript
- Echtzeit-Streaming
- Speaker-Identifikation (Zuordnung zu Personen/Namen) — liefert nur `A`, `B`, `C` … plus optionalen `speaker_map`-Override
- Zusammenfassung / Action Items / Q&A — lebt im Inbox-Modul als Enrichment-Pipeline
