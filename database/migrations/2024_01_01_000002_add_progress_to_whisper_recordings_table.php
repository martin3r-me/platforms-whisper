<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->unsignedInteger('chunks_total')->nullable()->after('duration_seconds');
            $table->unsignedInteger('chunks_done')->nullable()->after('chunks_total');
            $table->unsignedBigInteger('file_size_bytes')->nullable()->after('chunks_done');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropColumn(['chunks_total', 'chunks_done', 'file_size_bytes']);
        });
    }
};
