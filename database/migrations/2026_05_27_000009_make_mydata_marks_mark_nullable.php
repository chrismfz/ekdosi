<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `mydata_marks.mark` nullable.
 *
 * The column was created NOT NULL to mirror legacy MARK.MARK (which
 * could never be null in the legacy schema — every row represented
 * either an INSERT or CANCEL submission with a real AADE-issued MARK).
 *
 * PR #24's NullSubmitter introduced two new action values — 'SKIPPED'
 * and 'SKIPPED_CANCEL' — that legitimately have no MARK because no
 * AADE call ever happened. The previous workaround was inserting
 * empty string '', which:
 *   - pollutes the column with sentinel values that look like data
 *   - makes "show me un-filed invoices" queries confusing
 *     (WHERE mark IS NULL vs WHERE mark = '' vs WHERE mark IS NULL OR mark = '')
 *   - renders as a blank but copyable cell in the Filament audit
 *     RelationManager, which operators see as a bug
 *
 * Nullable matches the actual semantic: a SKIPPED row genuinely has
 * no MARK. NullSubmitter updated in the same PR to use null instead
 * of empty string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->string('mark', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Coerce any current nulls to '' before re-applying NOT NULL,
        // otherwise the ALTER fails on existing SKIPPED rows.
        \Illuminate\Support\Facades\DB::table('mydata_marks')
            ->whereNull('mark')
            ->update(['mark' => '']);

        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->string('mark', 50)->nullable(false)->change();
        });
    }
};
