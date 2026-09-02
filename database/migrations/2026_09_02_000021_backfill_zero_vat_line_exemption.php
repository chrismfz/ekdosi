<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-007 backfill: stamp existing 0% invoice lines with their tenant's §8.3
 * reason, so a LEGACY 0% invoice keeps its exemption citation once the tenant
 * gains more than one 0%-rate VatCategory (the new seed adds three).
 *
 * Without this, a legacy 0% line has vat_exemption_category = null and the
 * submitter/PDF fall back to the tenant's SINGLE 0% category — which stops being
 * a single once the seed/operator adds more, dropping the mandatory citation.
 *
 * Only the UNAMBIGUOUS case: a company with exactly ONE distinct 0%-rate reason
 * configured. Never guesses; a company with zero or several reasons is left as-is
 * (preflight/operator review handles those). Idempotent: only fills nulls.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_lines', 'vat_exemption_category')
            || ! Schema::hasColumn('vat_categories', 'vat_exemption_category')) {
            return;
        }

        // Companies whose 0%-rate categories all agree on ONE §8.3 reason.
        $companies = DB::table('vat_categories')
            ->select('company_id')
            ->where('rate', 0)
            ->whereNotNull('vat_exemption_category')
            ->groupBy('company_id')
            ->havingRaw('COUNT(DISTINCT vat_exemption_category) = 1')
            ->pluck('company_id');

        foreach ($companies as $companyId) {
            $reason = DB::table('vat_categories')
                ->where('company_id', $companyId)
                ->where('rate', 0)
                ->whereNotNull('vat_exemption_category')
                ->value('vat_exemption_category');

            if ($reason === null) {
                continue;
            }

            DB::table('invoice_lines')
                ->where('company_id', $companyId)
                ->where('vat_percent', 0)
                ->whereNull('vat_exemption_category')
                ->update(['vat_exemption_category' => $reason]);
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill (we can't tell a backfilled value from an
        // operator-set one). Leave the data in place on rollback.
    }
};
