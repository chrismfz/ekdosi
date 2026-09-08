<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C: a clean "Σε αναμονή" reason. The forgetful-customer case — a paid WHMCS
 * invoice whose customer wants a τιμολόγιο but hasn't given their ΑΦΜ yet —
 * gets parked in status='held' with an explicit reason (e.g. "Αναμονή για
 * ΑΦΜ") so a later operator knows WHY it's waiting, instead of a bare held
 * row. Distinct from rejected_reason (rejection is a final-ish decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->string('hold_reason', 200)->nullable()->after('rejected_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->dropColumn('hold_reason');
        });
    }
};
