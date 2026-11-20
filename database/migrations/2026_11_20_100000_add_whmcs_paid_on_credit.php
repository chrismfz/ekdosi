<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Πληρωμένο στο WHMCS ≠ εισπραγμένο» (ΤΠΥ7187 / WHMCS #32722).
 *
 * Some customers' WHMCS invoices are marked Paid only so WHMCS keeps the service running (no suspend/terminate,
 * domains get registered) — no money has come in. ekdosi must issue them ΕΠΙ ΠΙΣΤΩΣΕΙ, record no receipt, and
 * never treat that WHMCS «Paid» as a payment.
 *
 * - customers.whmcs_paid_on_credit: the standing per-customer setting — auto-issue HOLDS their rows for a human and
 *   the inbox modal pre-ticks «Δεν έχει πληρωθεί ακόμα».
 * - pending_whmcs_invoices.whmcs_paid_waived_at: set when a row is issued that way — the WHMCS settlement it saw
 *   (datepaid ≤ this) is not money: no receipt at issue, ignored by the payment sync / worklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('whmcs_paid_on_credit')->default(false);
        });
        Schema::table('pending_whmcs_invoices', function (Blueprint $table) {
            $table->timestamp('whmcs_paid_waived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('whmcs_paid_on_credit');
        });
        Schema::table('pending_whmcs_invoices', function (Blueprint $table) {
            $table->dropColumn('whmcs_paid_waived_at');
        });
    }
};
