<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer «Τιμολόγιο πριν την πληρωμή» flag — DISTINCT from
 * needs_immediate_invoice.
 *
 * needs_immediate_invoice = «κόψε + υπόβαλε ΑΥΤΟΜΑΤΑ μόλις ΠΛΗΡΩΘΕΙ» (auto-issue on pay).
 * needs_invoice_before_payment = «θέλει το τιμολόγιο ΠΡΙΝ πληρώσει» — δημόσιο / δήμοι /
 * μεγάλες Α.Ε. που εκδίδουν εντολή πληρωμής ΜΟΝΟ αφού λάβουν παραστατικό. Για αυτούς τους
 * πελάτες φέρνουμε τα ΑΠΛΗΡΩΤΑ WHMCS invoices τους στο Inbox (whmcs:fetch-unpaid) ώστε ο
 * χειριστής να τα εκδώσει χειροκίνητα επί πιστώσει — ΠΟΤΕ αυτόματα (το chooseType() κρατά
 * κάθε unpaid· η αυτόματη έκδοση απαιτεί needs_immediate_invoice, όχι αυτό το flag).
 *
 * Operator-owned: set στη φόρμα Πελάτη· καμία αυτόματη σύνδεση με WHMCS πεδίο.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t): void {
            $t->boolean('needs_invoice_before_payment')
                ->default(false)
                ->after('needs_immediate_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t): void {
            $t->dropColumn('needs_invoice_before_payment');
        });
    }
};
