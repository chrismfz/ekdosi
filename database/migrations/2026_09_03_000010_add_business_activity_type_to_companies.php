<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-006: the tenant's business-activity policy — reseller / manufacturer /
 * services / mixed. Drives the income-classification BUCKET (§8.6 category1_x)
 * that GOODS lines file under: a merchant's goods are «Εμπορεύματα» (category1_1),
 * a manufacturer's are «Προϊόντα» (category1_2). There is NO single correct
 * default, so the value is chosen per tenant (App\Support\MyData\ClassificationGuidance),
 * required by the go-live gate before a live filing.
 *
 * Nullable + no default on purpose: a null value means «not chosen yet», which the
 * go-live gate FAILS on so the operator makes the conscious choice (existing
 * tenants included) rather than inheriting a silent — possibly wrong — default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('business_activity_type', 20)->nullable()->after('kad_primary');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('business_activity_type');
        });
    }
};
