<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G8 phase 2 — auto-issue knob for γκρινιάρης (needs_immediate_invoice)
 * customers.
 *
 *   companies.whmcs_auto_issue_immediate  (default false)
 *     Per-tenant arming switch. When ON (and the scheduler-side flag
 *     EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE is also on — two-key arming), the
 *     whmcs:auto-issue command files paid inbox rows for γκρινιάρης
 *     customers at AADE WITHOUT operator review. Default OFF: this path
 *     issues legally-significant documents unattended, so it stays dark
 *     until an operator deliberately turns it on per tenant.
 *
 *   companies.whmcs_default_invoice_type_id  (nullable FK)
 *     The invoice type auto-issue uses (the manual inbox flow still lets
 *     the operator pick per row). Auto-issue REFUSES to run for a tenant
 *     with no default set — it must never guess the type. nullOnDelete so
 *     deleting a type doesn't cascade-delete the tenant; auto-issue then
 *     skips the tenant until a new default is chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('whmcs_auto_issue_immediate')
                ->default(false)
                ->after('whmcs_third_party_enabled');

            $table->foreignId('whmcs_default_invoice_type_id')
                ->nullable()
                ->after('whmcs_auto_issue_immediate')
                ->constrained('invoice_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('whmcs_default_invoice_type_id');
            $table->dropColumn('whmcs_auto_issue_immediate');
        });
    }
};
