<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR Stage B-2 followup: link pending_whmcs_invoices to the ekdosi
 * Invoice that was created during the File-at-AADE flow.
 *
 * Why this column matters:
 *
 * - Fix #3 (concurrent double-MARK): WhmcsInvoiceFiler::file() now
 *   lockForUpdate's the pending row at the start of its transaction.
 *   The first operator's tx allocates ΑΑ, creates Invoice + Lines,
 *   and atomically sets pending.invoice_id BEFORE committing. The
 *   second operator's tx waits for the lock, then sees invoice_id
 *   already set and refuses to re-allocate.
 *
 * - Fix #4 (post-AADE update failure → re-file → double-MARK): if
 *   the AADE submit succeeded but the post-update flipped the
 *   status flag and bailed, pending.invoice_id is still set. A
 *   retry click sees invoice_id set and refuses to allocate a fresh
 *   ΑΑ — operator is directed to the View Invoice page's "Submit to
 *   myDATA" action to complete the AADE filing on the EXISTING
 *   invoice (which is idempotent at that layer).
 *
 * - Fix #5 (ΑΑ gap on AADE failure): if AADE submit failed after
 *   the local invoice committed, the orphan Invoice carries the
 *   ΑΑ. With invoice_id linking it back to the pending row, the
 *   operator (or a future reconciliation job) can find and retry
 *   the AADE submit on the SAME Invoice instead of allocating a
 *   new ΑΑ.
 *
 * - UX: future Filament inbox + future Καρτέλα + future Stage B-3
 *   plugin all want to navigate pending → invoice without a
 *   secondary lookup through MARK.
 *
 * Nullable: most rows in production today don't have an invoice_id
 * (created by Stage B-1 ingestion, not yet filed). Required only
 * after the filer's tx commits.
 *
 * nullOnDelete: deleting an Invoice (force-delete from the panel)
 * shouldn't cascade-nuke the pending row. The pending row should
 * remain as audit evidence ("once linked to invoice 42, which
 * was force-deleted"), so a future investigator can see the
 * dangling state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t): void {
            $t->foreignId('invoice_id')
                ->nullable()
                ->after('customer_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('invoice_id');
        });
    }
};
