<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product-linked myDATA taxes + invoice-level tax RATES (the «δέσιμο» + the
 * «productionise»).
 *
 * - products.mydata_tax_* — bind a default fee/levy to a product (e.g. πλαστική
 *   σακούλα €0,07/τεμ, διανυκτέρευση €X/βραδιά). On invoice save the aggregator
 *   sums qty × per_unit per (taxType, category) into the invoice tax columns.
 * - invoices.*_rate — when set, the matching tax amount is RECOMPUTED on save as
 *   rate × net_total (authoritative net, header discount applied), instead of a
 *   frozen form value. Closes the «stale amount» issue: the form stores the rate,
 *   the server computes the amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('mydata_tax_type')->nullable()->after('vat_category_id');   // 2..5
            $table->unsignedSmallInteger('mydata_tax_category')->nullable()->after('mydata_tax_type'); // §8.x
            $table->decimal('mydata_tax_per_unit', 14, 4)->nullable()->after('mydata_tax_category');   // € per unit
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('withhold_rate', 7, 4)->nullable()->after('withhold_category');
            $table->decimal('fees_rate', 7, 4)->nullable()->after('fees_category');
            $table->decimal('other_taxes_rate', 7, 4)->nullable()->after('other_taxes_category');
            $table->decimal('stamp_duty_rate', 7, 4)->nullable()->after('stamp_duty_category');
            $table->decimal('deductions_rate', 7, 4)->nullable()->after('deductions_category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['mydata_tax_type', 'mydata_tax_category', 'mydata_tax_per_unit']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['withhold_rate', 'fees_rate', 'other_taxes_rate', 'stamp_duty_rate', 'deductions_rate']);
        });
    }
};
