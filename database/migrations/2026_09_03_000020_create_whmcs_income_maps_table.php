<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS → §8.6 income-classification map (MYD-006 bridge follow-up). Declares
 * «τι είναι» a WHMCS product GROUP (primary) or a single product (override):
 * υπηρεσία (category1_3) / εμπόρευμα (category1_1) / δικό μας προϊόν (category1_2).
 *
 * Group-first on purpose: a tenant maps «Web Hosting» once and every new package
 * in that group inherits it — no re-mapping per package. `scope` supports a
 * per-product override too (resolved product→group→fallback), so the product half
 * can ship later with no schema change. Applied at ingest by stamping the invoice
 * line's `mydata_income_class_category`; the submitter reads that first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whmcs_income_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // 'group' = a WHMCS product group (tblproductgroups.id); 'product' = a
            // single WHMCS product (tblproducts.id). The line's product override
            // wins over its group.
            $table->string('scope', 16); // group | product
            $table->unsignedBigInteger('whmcs_key'); // gid or pid depending on scope
            // The §8.6 bucket this group/product files under (category1_1/1_2/1_3).
            $table->string('income_class_category', 32);
            // Optional E3 type override (E3_561_xxx); normally null — the E3 type is
            // channel-driven and stays on the invoice type. Kept for symmetry with
            // the product-category override pair.
            $table->string('income_class', 32)->nullable();
            // Display convenience: the WHMCS group name at mapping time (so the
            // list reads «Web Hosting» even if the catalogue fetch is stale).
            $table->string('label', 191)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'scope', 'whmcs_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_income_maps');
    }
};
