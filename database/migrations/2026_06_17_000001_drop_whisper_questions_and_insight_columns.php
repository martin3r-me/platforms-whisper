<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whisper scope cleanup — higher-level layers (summary, action items, outline,
 * AI suggestions, Q&A) moved to the Inbox enrichment pipeline. Whisper is now
 * scoped to transcripts + speakers + segments only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('whisper_questions');

        Schema::table('whisper_recordings', function (Blueprint $table) {
            foreach (['summary', 'action_items', 'outline', 'ai_suggestions'] as $column) {
                if (Schema::hasColumn('whisper_recordings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally irreversible — these layers live in Inbox now.
    }
};
