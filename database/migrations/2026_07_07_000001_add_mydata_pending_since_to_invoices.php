<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-2 (AUDIT, σκέλος γ) — "in-doubt" marker for a submission whose TRANSPORT
 * failed (timeout / connection drop). The POST may or may not have reached AADE,
 * and AADE does NOT dedup a resubmission of the same (series, ΑΑ) — sandbox-proven
 * 2026-07-07: the same invoiceUid produced TWO distinct MARKs, i.e. a blind retry
 * double-declares income. This column records WHEN the ambiguity started; the
 * mirror mydata_state stays null (still "not filed") so every existing predicate
 * is unchanged. MyDataSubmitter::submit() reconciles an in-doubt invoice against
 * RequestTransmittedDocs BEFORE it is allowed to POST again, adopting an existing
 * MARK instead of filing a second one. Cleared on a successful filing/adoption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('mydata_pending_since')->nullable()->after('mydata_url');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('mydata_pending_since');
        });
    }
};
