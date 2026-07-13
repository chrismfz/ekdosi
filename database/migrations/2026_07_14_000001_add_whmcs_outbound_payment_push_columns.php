<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (outbound payment push, ekdosi → WHMCS mark-paid).
 *
 * - companies.whmcs_push_payments — per-tenant OPT-IN (default OFF). No write
 *   ever lands in the customer's WHMCS unless the tenant explicitly turns this
 *   on (mirrors the money-nervous posture of every other WHMCS write flag).
 * - pending_whmcs_invoices.whmcs_payment_pushed_at — the idempotency marker:
 *   once we've marked a WHMCS invoice paid (or confirmed it was already Paid),
 *   we stamp this so the same settlement is never pushed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('whmcs_push_payments')->default(false)->after('whmcs_fetch_via_bridge');
        });

        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->timestamp('whmcs_payment_pushed_at')->nullable()->after('filed_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('whmcs_push_payments');
        });

        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->dropColumn('whmcs_payment_pushed_at');
        });
    }
};
