<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-1b (timologia v2): third-party-invoicing resolution + billing.
 *
 * - companies.whmcs_third_party_enabled — per-tenant BACKEND kill-switch
 *   (default OFF). With it off the ingestor never calls the bridge's
 *   resolve.php and behaves exactly as today. Operator flips it on per tenant
 *   once resolve.php is deployed and they're ready to test live. This is the
 *   backend complement to the (later) client-area hide toggle.
 *
 * - pending_whmcs_invoices.third_party_state — null (not evaluated) /
 *   'none' (resolved, no routing) / 'single' (whole invoice → one third party)
 *   / 'multi' (mixes billing parties → parked as held for the operator split,
 *   T-1c).
 * - pending_whmcs_invoices.third_party_resolution — the raw resolve.php
 *   snapshot kept for audit + the inbox display.
 *
 * - customers.whmcs_reseller_routes — count of third-party routing rows this
 *   customer owns in WHMCS (0 = not a reseller). Drives the "routes invoices
 *   to third parties" badge so an operator can sanity-check whose invoices
 *   they really are. Maintained by `whmcs:sync-resellers` (read-only mirror).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('whmcs_third_party_enabled')
                ->default(false)
                ->after('whmcs_invoice_min_date');
        });

        Schema::table('pending_whmcs_invoices', function (Blueprint $table) {
            $table->string('third_party_state', 16)->nullable()->after('match_reason');
            $table->json('third_party_resolution')->nullable()->after('third_party_state');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('whmcs_reseller_routes')
                ->default(0)
                ->after('whmcs_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whmcs_third_party_enabled');
        });

        Schema::table('pending_whmcs_invoices', function (Blueprint $table) {
            $table->dropColumn(['third_party_state', 'third_party_resolution']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('whmcs_reseller_routes');
        });
    }
};
