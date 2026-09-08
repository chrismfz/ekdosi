<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-looking columns on products. None are sourced from legacy
 * Firebird PRODUCT — the ETL leaves them at their defaults (is_active
 * true, the rest null). Operators populate them via the new
 * ProductResource.
 *
 * - is_active:          parallel to customers.is_active. Hides discontinued
 *                       products from new-invoice pickers without
 *                       soft-deleting (which would break invoice line audit).
 * - internal_notes:     operator-only memo, NOT shown on invoices. Separate
 *                       from `description` which is public-facing.
 * - sku:                internal stock-keeping code. Distinct from `barcode`
 *                       (scannable EAN/UPC); SKU is the stable internal
 *                       identifier external integrations key against.
 *                       Unique per tenant.
 * - whmcs_product_id:   link to WHMCS product/service ID. Same convention
 *                       as customers.whmcs_client_id. Set by the future
 *                       WHMCS bridge but editable manually now. Unique per
 *                       tenant.
 * - supplier:           free-text supplier label ("cPanel", "Namecheap"),
 *                       NOT a Supplier FK. Lets operators answer "where
 *                       did this come from?" for resold items without a
 *                       full supplier CRM. If a real Supplier model lands
 *                       later, this becomes a backfill source.
 *
 * Note: ETL re-runs will wipe operator-edited values on these columns the
 * same way they used to on customers before snapshotManualEdits() existed.
 * If/when that becomes a real problem (operator does a serious re-run with
 * manual product edits in place), mirror the customers snapshot/restore
 * pattern in MigrateFromFirebird. Not done preemptively — the gap doesn't
 * bite until all three (ProductResource live + edits made + ETL re-run)
 * happen, and the columns are small + named so we can add the snapshot
 * cleanly when needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_active')->default(true)->after('description_short');
            $t->string('sku', 40)->nullable()->after('barcode');
            $t->string('supplier', 120)->nullable()->after('description');
            $t->text('internal_notes')->nullable()->after('supplier');
            $t->unsignedInteger('whmcs_product_id')->nullable()->after('internal_notes');

            $t->unique(['company_id', 'sku']);
            $t->unique(['company_id', 'whmcs_product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'whmcs_product_id']);
            $t->dropUnique(['company_id', 'sku']);
            $t->dropColumn(['is_active', 'sku', 'supplier', 'internal_notes', 'whmcs_product_id']);
        });
    }
};
