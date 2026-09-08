<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B-3 (PR #48): structured WHMCS write-back state on the
 * pending row.
 *
 * Replaces the original "stuff the writeback outcome into the
 * free-text notes column" approach. Two columns:
 *
 *   - whmcs_writeback_state: enum-ish string
 *       null        -> off-mode tenant (no MARK to push) — N/A
 *       'pending'   -> we have a MARK + a configured bridge; the
 *                      write-back is in flight or hasn't run yet
 *       'succeeded' -> tblinvoices.invoiced set on the WHMCS side
 *       'failed'    -> bridge call threw; see whmcs_writeback_error
 *       'skipped'   -> bridge not deployed/configured for this tenant
 *
 *   - whmcs_writeback_error: the diagnostic from the last failed
 *     attempt (null when state != 'failed').
 *
 * Why structured columns instead of notes-text:
 *   - Operability: a dashboard can `WHERE whmcs_writeback_state =
 *     'failed'` to count filed invoices whose WHMCS bookkeeping is
 *     out of sync, without regex-grepping a TEXT column.
 *   - Retryability: a future `whmcs:retry-writebacks` command can
 *     target state='failed'/'pending' rows precisely.
 *   - Atomicity: the columns are EXCLUDED from the audit-freeze
 *     observer's lock (PendingWhmcsInvoiceObserver), so the
 *     write-back can run AFTER the status=filed transition and
 *     update only these columns. That ordering means the legal
 *     status=filed snapshot (status + filed_at + mydata_mark) is
 *     committed atomically with the AADE result FIRST, and the
 *     write-back outcome is recorded separately — a write-back DB
 *     failure can no longer leave the filing itself half-recorded.
 *
 * Index on (company_id, whmcs_writeback_state) for the future
 * retry-sweep query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t): void {
            $t->string('whmcs_writeback_state', 20)
                ->nullable()
                ->after('mydata_mark');
            $t->text('whmcs_writeback_error')
                ->nullable()
                ->after('whmcs_writeback_state');
            $t->index(['company_id', 'whmcs_writeback_state'], 'pwi_company_writeback_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t): void {
            $t->dropIndex('pwi_company_writeback_idx');
            $t->dropColumn(['whmcs_writeback_state', 'whmcs_writeback_error']);
        });
    }
};
