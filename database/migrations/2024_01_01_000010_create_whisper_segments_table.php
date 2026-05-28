<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whisper_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whisper_recording_id')->constrained('whisper_recordings')->cascadeOnDelete();
            $table->foreignId('whisper_speaker_id')->nullable()->constrained('whisper_speakers')->nullOnDelete();
            $table->string('speaker_label', 16);
            $table->text('text');
            $table->float('start_seconds');
            $table->float('end_seconds');
            $table->string('embedding_key', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['whisper_recording_id', 'sort_order']);
            $table->index('whisper_speaker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whisper_segments');
    }
};
