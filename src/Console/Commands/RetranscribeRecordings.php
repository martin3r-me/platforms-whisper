<?php

namespace Platform\Whisper\Console\Commands;

use Illuminate\Console\Command;
use Platform\Whisper\Models\WhisperRecording;
use Platform\Whisper\Services\RecordingIntakeService;

/**
 * Transkribiert Aufnahmen neu aus dem gesicherten Original-Audio — nach einem
 * Fehler des Transkriptions-Dienstes oder um mit einem besseren Modell neu zu
 * rechnen. Aufnahmen ohne gesichertes Audio (z. B. alte Plaud-Importe, die nur
 * Plauds Transkript haben) werden übersprungen.
 */
class RetranscribeRecordings extends Command
{
    protected $signature = 'whisper:retranscribe
        {--id= : Nur diese eine Recording-ID.}
        {--failed : Alle fehlgeschlagenen Aufnahmen.}
        {--team= : Auf dieses Team einschränken.}
        {--multichannel : Als Dual-Spur transkribieren (Mac-Aufnahmen).}';

    protected $description = 'Transkribiert Aufnahmen neu aus dem gesicherten Audio.';

    public function handle(RecordingIntakeService $intake): int
    {
        if (!$this->option('id') && !$this->option('failed')) {
            $this->error('--id=<id> oder --failed angeben.');
            return self::FAILURE;
        }

        $query = WhisperRecording::query();
        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } else {
            $query->where('status', WhisperRecording::STATUS_FAILED);
        }
        if ($team = $this->option('team')) {
            $query->where('team_id', (int) $team);
        }

        $queued = 0;
        $skipped = 0;
        foreach ($query->orderBy('id')->cursor() as $recording) {
            // Mac-Aufnahmen sind immer Dual-Spur.
            $multichannel = $this->option('multichannel') || $recording->source === 'mac';
            if ($intake->retranscribe($recording, $multichannel)) {
                $queued++;
            } else {
                $skipped++;
                $this->line("#{$recording->id}: kein gesichertes Audio — übersprungen.");
            }
        }

        $this->info("{$queued} zur Transkription eingereiht, {$skipped} übersprungen.");

        return self::SUCCESS;
    }
}
