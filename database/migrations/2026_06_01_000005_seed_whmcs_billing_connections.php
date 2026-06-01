<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 (Bridges/Connectors): register a `whmcs` billing connection for every
 * company that already has WHMCS configured (whmcs_api_url set), so the existing
 * tenants show up in the registry without a manual step. Idempotent — skips a
 * company that already has a whmcs connection. Raw queries (no model events /
 * global scopes) — this is a data backfill.
 *
 * New companies that configure WHMCS later get their connection via
 * BillingConnection::ensureFor (Phase 1 wiring / the «Γέφυρες» tab).
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')
            ->whereNotNull('whmcs_api_url')
            ->where('whmcs_api_url', '!=', '')
            ->pluck('whmcs_api_url', 'id');

        $now = now();
        foreach ($companies as $companyId => $apiUrl) {
            $exists = DB::table('billing_connections')
                ->where('company_id', $companyId)
                ->where('source', 'whmcs')
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('billing_connections')->insert([
                'company_id' => $companyId,
                'source' => 'whmcs',
                'label' => 'WHMCS',
                'is_active' => true,
                'config' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Only remove the rows this migration could have created (auto-seeded
        // WHMCS connections), leaving any operator-created connections intact.
        DB::table('billing_connections')
            ->where('source', 'whmcs')
            ->where('label', 'WHMCS')
            ->delete();
    }
};
