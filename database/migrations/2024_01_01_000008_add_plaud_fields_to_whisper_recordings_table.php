<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->json('outline')->nullable()->after('action_items');
            $table->text('ai_suggestions')->nullable()->after('outline');
            $table->string('device_serial', 64)->nullable()->after('provider_id');
            $table->string('source_url', 2048)->nullable()->after('device_serial');
            $table->timestamp('recorded_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropColumn(['outline', 'ai_suggestions', 'device_serial', 'source_url', 'recorded_at']);
        });
    }
};
