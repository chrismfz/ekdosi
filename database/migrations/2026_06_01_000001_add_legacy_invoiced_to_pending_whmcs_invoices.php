<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-run visibility ("test new, keep invoicing from old"): record whether a
 * staged WHMCS invoice has ALSO been invoiced in the LEGACY ekdosi app
 * (tblinvoices.invoiced != 0 on the WHMCS side). The inbox surfaces it as a
 * badge + filter so an operator doesn't issue an ekdosi παραστατικό for an
 * invoice the partner already filed from the old app.
 *
 *   null = unknown (not checked / no bridge configured)
 *   0    = not invoiced in the legacy app
 *   >0   = invoiced in the legacy app (1 = legacy flag, larger = an old MARK
 *          left over before the SMALLINT rollback)
 *
 * Populated by the bridge's read-only invoiced_flags endpoint, refreshed for
 * still-actionable rows by whmcs:fetch-pending and the inbox «Έλεγχος legacy»
 * action — getPendingInvoices excludes already-filed invoices at fetch time,
 * so the value that matters is the one a row PICKS UP after it was staged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('legacy_invoiced')->nullable()->after('mydata_mark');
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->dropColumn('legacy_invoiced');
        });
    }
};
