<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen pending_whmcs_invoices.hold_reason from varchar(200) to TEXT.
 *
 * The original 200-char limit fit short reasons (e.g. "Αναμονή για ΑΦΜ"), but
 * the consolidated/mass-pay hold reason (PendingWhmcsInvoice::consolidatedPaymentReason)
 * is a full Greek explanation that already runs ~216 chars with zero refs and
 * grows with each referenced invoice — overflowing 200. On strict-mode MariaDB
 * that insert throws "Data too long" INSIDE the ingest transaction, rolling
 * back the whole staging (a 500 on the webhook / a failed fetch row). TEXT
 * removes the ceiling; the column is display-only (inbox tooltip), nullable,
 * un-indexed — no downside to widening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->text('hold_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->string('hold_reason', 200)->nullable()->change();
        });
    }
};
