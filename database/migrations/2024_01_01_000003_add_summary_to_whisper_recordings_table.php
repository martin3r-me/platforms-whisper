<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->longText('summary')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
