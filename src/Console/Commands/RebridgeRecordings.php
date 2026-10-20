<?php

namespace Platform\Whisper\Console\Commands;

use Illuminate\Console\Command;
use Platform\Whisper\Jobs\IngestRecordingToInboxJob;
use Platform\Whisper\Models\WhisperRecording;

/**
 * Holt completed WhisperRecordings nachträglich in den Eingang, indem der
 * Bridge-Job je Aufnahme neu dispatcht wird. Nötig für Aufnahmen, die
 * entstanden sind, während der Observer-Bug (created+completed dispatchte
 * nie) aktiv war oder die Queue stand.
 *
 * Idempotent: InboxAudioIngestionService dedupt auf (source_type, source_id),
 * ein bereits vorhandenes Item wird nur zurückgegeben, nicht dupliziert.
 * Soft-coupled: ohne gebundene RecordingIngestion-Naht (inbox fehlt) No-op.
 */
class RebridgeRecordings extends Command
{
    protected $signature = 'whisper:rebridge-recordings
        {--days=7 : Zeitfenster in Tagen (nach created_at). 0 = alle.}
        {--id= : Nur diese eine Recording-ID.}
        {--team= : Auf dieses Team einschränken.}';

    protected $description = 'Dispatcht den Eingang-Bridge-Job für completed Aufnahmen nach (idempotent).';

    public function handle(): int
    {
        if (!interface_exists(\Platform\Core\Inbox\Contracts\RecordingIngestion::class)) {
            $this->warn('RecordingIngestion-Naht nicht gebunden (inbox-Modul fehlt) — nichts zu tun.');
            return self::SUCCESS;
        }

        $query = WhisperRecording::query()
            ->where('status', WhisperRecording::STATUS_COMPLETED);

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } elseif (($days = (int) $this->option('days')) > 0) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        if ($team = $this->option('team')) {
            $query->where('team_id', (int) $team);
        }

        $ids = $query->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('Keine passenden Aufnahmen gefunden.');
            return self::SUCCESS;
        }

        foreach ($ids as $recordingId) {
            try {
                IngestRecordingToInboxJob::dispatch((int) $recordingId);
            } catch (\Throwable $e) {
                $this->error("#{$recordingId}: dispatch fehlgeschlagen — {$e->getMessage()}");
            }
        }

        $this->info("Re-Bridge dispatcht für {$ids->count()} Aufnahme(n). Verarbeitung läuft über die Queue.");
        return self::SUCCESS;
    }
}
