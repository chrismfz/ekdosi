<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #29: ETL re-run safety. The new MigrateFromFirebird logic upserts
 * on (company_id, legacy_id) so existing legacy-imported rows keep
 * their surrogate id across re-runs and Filament-managed columns
 * survive. Most tables with legacy_id already have this unique index
 * (verified across migrations 000003-000019); invoices is the one
 * exception — it has only (company_id, invcode) unique.
 *
 * Adding the constraint also enforces data integrity: per-tenant a
 * single legacy invoice_id cannot map to two ekdosi rows. NULL
 * legacy_ids are allowed (multiple ekdosi-only invoices per tenant)
 * because MariaDB + SQLite both treat NULL values as not-equal in
 * unique constraints — so the constraint is "no two non-null
 * (company_id, legacy_id) pairs collide".
 */
return new class extends Migration
{
    public function up(): void
    {
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
