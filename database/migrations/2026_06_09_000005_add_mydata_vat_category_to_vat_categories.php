<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional explicit AADE §8.2 vatCategory override per VAT category.
 *
 * Some rates are ambiguous: a 4% rate maps to firebed VatCategory 6 (pre-existing
 * island regime) OR 10 (αρ.31 ν.5057/2023); 3% → 9 (ν.5057). The submitter derives
 * the code from the rate by default (4%→6), but a tenant on the ν.5057 regime sets
 * this override (4%→10, 3%→9) so the right code is filed. Null = derive from rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('mydata_vat_category')->nullable()->after('vat_exemption_category');
        });
    }

    public function down(): void
    {
        Schema::table('vat_categories', function (Blueprint $table) {
            $table->dropColumn('mydata_vat_category');
        });
    }
};
