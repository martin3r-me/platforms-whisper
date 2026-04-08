<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whisper_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            $table->string('title')->nullable();
            $table->longText('transcript')->nullable();
            $table->string('language', 8)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('model')->default('whisper-1');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at'], 'whisper_recordings_team_created_idx');
            $table->index(['team_id', 'status'], 'whisper_recordings_team_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whisper_recordings');
    }
};
