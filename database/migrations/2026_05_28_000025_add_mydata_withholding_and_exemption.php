<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * myDATA filing-correctness gaps G1 (withholding) + G4 (VAT-exempt).
 *
 * - vat_categories.vat_exemption_category — when a VAT category is 0% (exempt),
 *   AADE requires vatCategory=7 + an exemption reason code (§8.3, 1–31). We
 *   store that reason on the 0%-rate category so MyDataSubmitter can emit it.
 *   Null for normal rated categories.
 *
 * - invoices.withhold_category — when an invoice carries a withholding amount,
 *   AADE needs a taxesTotals[taxType=1] block naming the withholding category
 *   (§8.4, 1–18) alongside the amount. The category depends on the service
 *   (fees 20%, technicians 4/10%, lawyers 15%…) so it's per-invoice, not a
 *   tenant constant. Null = no withholding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('vat_exemption_category')->nullable()->after('rate');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('withhold_category')->nullable()->after('withhold_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vat_categories', function (Blueprint $table) {
            $table->dropColumn('vat_exemption_category');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('withhold_category');
        });
    }
};
