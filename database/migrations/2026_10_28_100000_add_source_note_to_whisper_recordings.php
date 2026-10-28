<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quell-Notiz (source_note): roher, vom Anbieter MITGELIEFERTER Text/Markdown
 * zur Aufnahme (heute: Plauds eigene Zusammenfassung/Notiz). Reines
 * Quell-Artefakt — wie source_url/device_serial. whisper VERARBEITET das nicht
 * (bleibt Transkript-Schicht); die Notiz wird nur durch die Ingestion gereicht
 * und dort als sekundäre Enrichment am Inbox-Item abgelegt. So geht Plauds
 * fertige Zusammenfassung nicht mehr verloren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->longText('source_note')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropColumn('source_note');
        });
    }
};
