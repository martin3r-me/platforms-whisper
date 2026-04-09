<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whisper_questions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('whisper_recording_id')->constrained('whisper_recordings')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->string('status', 20)->default('completed'); // completed | failed
            $table->timestamps();

            $table->index(['whisper_recording_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whisper_questions');
    }
};
