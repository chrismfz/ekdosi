<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-023: delivery notes get the distinct cancellation-MARK field invoices
 * already have.
 *
 * AADE returns its OWN `cancellationMark` for a successful cancellation — it is
 * separate evidence from the MARK of the document being cancelled. `mydata_marks`
 * has carried both since 2026-06-05, but `delivery_marks` never did, so
 * `persistCancellation()` wrote the ISSUE mark into `mark` on a row whose action
 * is CANCEL. The database therefore ASSERTED that the issue MARK was the
 * cancellation evidence — worse than storing nothing, because it reads as proof.
 *
 * Same shape as mydata_marks.cancellation_mark so the two tables answer the
 * question the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('delivery_marks', 'cancellation_mark')) {
            return;
        }

        Schema::table('delivery_marks', function (Blueprint $table): void {
            $table->string('cancellation_mark', 40)->nullable()->after('mark');
        });
    }

    /**
     * Refuses when the column holds evidence that exists NOWHERE else.
     *
     * The companion backfill (…000005) moves a cancellation MARK OUT of `mark`
     * and into this column, so once it has run, dropping the column destroys the
     * only remaining copy — silently, on a routine `migrate:rollback`. The
     * supported rollback path restores a snapshot (`deploy/rollback.sh` /
     * `ekdosi:db-restore`), which is unaffected by this guard; a manual rollback
     * over live cancellations is told to do the same rather than allowed to erase
     * a legal audit trail.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('delivery_marks', 'cancellation_mark')) {
            return;
        }

        $held = DB::table('delivery_marks')->whereNotNull('cancellation_mark')->count();

        if ($held > 0) {
            throw new RuntimeException(
                "Refusing to drop delivery_marks.cancellation_mark: {$held} row(s) hold a cancellation "
                .'MARK that exists in no other column, and dropping it would destroy legal filing '
                .'evidence. Restore a snapshot instead (deploy/rollback.sh, or php artisan ekdosi:db-restore).'
            );
        }

        Schema::table('delivery_marks', function (Blueprint $table): void {
            $table->dropColumn('cancellation_mark');
        });
    }
};
