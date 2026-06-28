<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `target_inbox_item_id` records the meeting (or other primary) inbox
 * item that this recording was *intended for* at upload time. The Mac
 * client fetches the currently-active meeting from the Inbox API and
 * passes its id with the dual-channel upload — the bridge later uses
 * this column to call InboxItemLinkContract::supplements() and connect
 * the recording-channel InboxItem to the meeting-channel one.
 *
 * Nullable + no FK: an inbox item may be soft-deleted or live in a
 * separate database in future deployments; the link service handles
 * missing targets gracefully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->unsignedBigInteger('target_inbox_item_id')->nullable()->after('source_url');
            $table->index('target_inbox_item_id', 'whisper_recordings_target_inbox_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whisper_recordings', function (Blueprint $table) {
            $table->dropIndex('whisper_recordings_target_inbox_item_idx');
            $table->dropColumn('target_inbox_item_id');
        });
    }
};
