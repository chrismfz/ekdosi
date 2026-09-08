<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #34 (WHMCS bridge - Stage B-1 followup): per-tenant cutoff date
 * for WHMCS pull operations.
 *
 * Long-running tenants have historical invoices that should NEVER be
 * filed at AADE - test invoices, staff promo runs, product trials,
 * legacy data predating any obligation to file. The legacy
 * prepare_for_ekdosi plugin may have left these at invoiced=0
 * indefinitely (it only flips to MARK on a successful ekdosi-side
 * file, which never happened for these rows by intent).
 *
 * Without a cutoff, the Fetch button pulls ALL historical
 * invoiced=0 rows into the operator inbox - potentially tens of
 * thousands of rows that need manual rejection one by one. With a
 * cutoff (typically the tenant's ekdosi-cutover date), we skip
 * everything older during the WHMCS pull and never even stage them.
 *
 * Stored as a plain date (no timezone); compared lexicographically
 * against WHMCS's `date` field which is also YYYY-MM-DD shape.
 *
 * Null = no cutoff (pull everything that's pending; default).
 * Operators are expected to set this at first WHMCS bridge configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->date('whmcs_invoice_min_date')->nullable()->after('whmcs_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn('whmcs_invoice_min_date');
        });
    }
};
