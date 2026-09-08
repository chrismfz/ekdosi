<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change delivery_marks.mark_time from TIMESTAMP to TIME — the exact twin of
 * the invoice-side fix (2026_05_27_000002_change_mark_time_to_time_on_mydata_marks).
 *
 * The create migration (2026_06_03_000003) already declares `$t->time('mark_time')`,
 * but the live column drifted to TIMESTAMP (it was migrated before the source was
 * corrected, and migrations never re-run). DeliveryNoteSubmitter / DeliveryLifecycleService
 * persist the MARK wall-clock with `now()->toTimeString()` ('HH:MM:SS') — a TIME value
 * that MariaDB's STRICT_TRANS_TABLES rejects on a TIMESTAMP column (SQLSTATE 22007 /
 * 1292), so the issue INSERT rolled back and the note never reached VALID. Realigning
 * the live column to TIME (matching mydata_marks) unblocks the delivery round-trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_marks', function (Blueprint $t) {
            $t->time('mark_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_marks', function (Blueprint $t) {
            $t->timestamp('mark_time')->nullable()->change();
        });
    }
};
