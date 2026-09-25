<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `movement_checked_at` (delivery_notes + ΤΔΑ invoices): when delivery:refresh-status last
 * re-read the movement from AADE. The scheduler polls the LEAST-recently-checked first —
 * ordering by updated_at starved every document beyond the per-run cap, because a status
 * query that changes nothing doesn't save the row. Written with a raw update (no
 * updated_at bump, no activity-log entry): it is poll bookkeeping, not document data.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['delivery_notes', 'invoices'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->timestamp('movement_checked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['delivery_notes', 'invoices'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('movement_checked_at');
            });
        }
    }
};
