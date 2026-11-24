<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quelle einer Aufnahme (source + source_id) als eigene Spalten. Bisher stand
 * der Dedup-Key von Importen ("plaud:<file_id>") in provider_id — dieselbe
 * Spalte, in die AssemblyAI seine Transcript-ID schreibt. Sobald Whisper
 * Plaud-Audio selbst transkribiert, hätte AssemblyAI den Dedup-Key
 * überschrieben. Jetzt: source/source_id = woher (Dedup), provider_id = wer
 * transkribiert hat.
 *
 * Backfill: Importe ("import:<source>") mit "<source>:<id>" in provider_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('model');
            $table->string('source_id', 191)->nullable()->after('source');
            $table->unique(['team_id', 'source', 'source_id'], 'whisper_recordings_team_source_unique');
        });

        DB::table('whisper_recordings')
            ->where('model', 'like', 'import:%')
            ->where('provider_id', 'like', '%:%')
            ->orderBy('id')
            ->select(['id', 'provider_id'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    [$source, $sourceId] = explode(':', (string) $row->provider_id, 2);
                    if ($source === '' || $sourceId === '') {
                        continue;
                    }
                    DB::table('whisper_recordings')->where('id', $row->id)->update([
                        'source' => $source,
                        'source_id' => $sourceId,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropUnique('whisper_recordings_team_source_unique');
            $table->dropColumn(['source', 'source_id']);
        });
    }
};
