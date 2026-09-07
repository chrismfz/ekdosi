<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket merge (Πυλώνας E, Phase 4). When two duplicate tickets of the SAME owner
 * are merged, the source's messages/watchers move to the surviving target and the
 * source is closed with `merged_into_id` pointing at the target (a terminal state:
 * a merged ticket can't reopen). Self-referencing FK, nulled if the target is ever
 * hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->foreignId('merged_into_id')->nullable()->after('rated_at')
                ->constrained('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('merged_into_id');
        });
    }
};
