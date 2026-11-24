<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lose Kopplung einer Aufnahme an beliebige Objekte (Morph-Alias + ID), z. B. ein
 * Meeting in gathering ('gathering_meeting'). Gleiche Mechanik wie slides_deck_links:
 * Der Link gehört dem Objekt, die Aufnahme hängt daran — whisper kennt die Module nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whisper_recording_links')) {
            return;
        }

        Schema::create('whisper_recording_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_id')->constrained('whisper_recordings')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('linkable_type', 100);
            $table->unsignedBigInteger('linkable_id');
            $table->string('role', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recording_id', 'linkable_type', 'linkable_id'], 'whisper_recording_links_unique');
            $table->index(['linkable_type', 'linkable_id'], 'whisper_recording_links_linkable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whisper_recording_links');
    }
};
