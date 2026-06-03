<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: tag notes with their origin. NULL = operator-authored;
 * 'backup' = synced from an import (Epsilon Remarks / legacy DETAILS). The
 * importer upserts ONE 'backup' note per customer keyed on this, so re-runs
 * update in place instead of piling up duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $t) {
            $t->string('source')->nullable()->after('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $t) {
            $t->dropColumn('source');
        });
    }
};
