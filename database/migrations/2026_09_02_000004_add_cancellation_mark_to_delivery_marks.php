<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    public function down(): void
    {
        Schema::table('delivery_marks', function (Blueprint $table): void {
            $table->dropColumn('cancellation_mark');
        });
    }
};
