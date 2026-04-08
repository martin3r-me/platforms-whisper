<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->json('speaker_map')->nullable()->after('speakers_count');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropColumn('speaker_map');
        });
    }
};
