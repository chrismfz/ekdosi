<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR #29: ETL re-run safety. The new MigrateFromFirebird logic upserts
 * on (company_id, legacy_id) so existing legacy-imported rows keep
 * their surrogate id across re-runs and Filament-managed columns
 * survive. Most tables with legacy_id already have this unique index
 * (verified across migrations 000003-000019); invoices is the one
 * exception — it had only (company_id, invcode) unique.
 *
 * Adding the constraint also enforces data integrity: per-tenant a
 * single legacy invoice_id cannot map to two ekdosi rows. NULL
 * legacy_ids are allowed (multiple ekdosi-only invoices per tenant)
 * because MariaDB + SQLite both treat NULL values as not-equal in
 * unique constraints — so the constraint is "no two non-null
 * (company_id, legacy_id) pairs collide".
 *
 * Pre-check before adding the constraint: count any pre-existing
 * duplicate (company_id, legacy_id) pairs. If duplicates exist
 * (shouldn't, because the prior ETL did wipeCompany() and only the
 * ETL writes legacy_id), surface them with a clear error rather
 * than letting MariaDB report a generic "Duplicate entry" failure
 * on the ALTER TABLE that the operator can't trace to a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Surface pre-existing duplicates with row pointers so an
        // operator can fix them BEFORE the ALTER fails. Cheaper than
        // debugging "[42S02] Duplicate entry" against an unindexed
        // table at 3am on cutover day.
        $duplicates = DB::table('invoices')
            ->select('company_id', 'legacy_id', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('legacy_id')
            ->groupBy('company_id', 'legacy_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $pointers = $duplicates->map(fn ($d) =>
                "company_id={$d->company_id} legacy_id={$d->legacy_id} count={$d->duplicate_count}"
            )->implode('; ');
            throw new \RuntimeException(
                'Cannot add unique(company_id, legacy_id) on invoices — '.
                $duplicates->count().' duplicate pair(s) exist. Inspect: '.$pointers.
                '. Resolve by keeping one row per (company_id, legacy_id) before running migrate again.'
            );
        }

        Schema::table('invoices', function (Blueprint $t): void {
            $t->unique(['company_id', 'legacy_id'], 'invoices_company_legacy_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t): void {
            $t->dropUnique('invoices_company_legacy_unique');
        });
    }
};
