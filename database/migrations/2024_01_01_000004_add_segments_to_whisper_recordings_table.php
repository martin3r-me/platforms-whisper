<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->json('segments')->nullable()->after('summary');
            $table->unsignedSmallInteger('speakers_count')->nullable()->after('segments');
            $table->string('provider_id', 191)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropColumn(['segments', 'speakers_count', 'provider_id']);
        });
    }
};
