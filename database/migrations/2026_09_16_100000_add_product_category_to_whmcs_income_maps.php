<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue-by-category (#2): a tenant declares, per WHMCS product group/product,
 * WHICH ekdosi business ProductCategory its invoice lines belong to — the reporting
 * axis for «Έσοδα ανά κατηγορία». Sits next to the existing §8.6 income-class
 * mapping on the same «Αντιστοίχιση WHMCS» page. Nullable: a group can carry a
 * myDATA class without (yet) an ekdosi category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_income_maps', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_category_id')->nullable()->after('income_class');

            $table->foreign('product_category_id', 'whmcs_income_maps_product_category_fk')
                ->references('id')->on('product_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_income_maps', function (Blueprint $table): void {
            $table->dropForeign('whmcs_income_maps_product_category_fk');
            $table->dropColumn('product_category_id');
        });
    }
};
