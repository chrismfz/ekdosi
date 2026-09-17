<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue-by-category (#2): the resolved ekdosi ProductCategory stamped on a line.
 * WHMCS-imported lines carry NO product_id (free-text descriptions), so they can't
 * reach a category via product→category — the ingestion stamps this from the WHMCS
 * income-map instead. A product-linked line leaves it null and the report falls back
 * to product→category. Nullable, nullOnDelete (a deleted category must not delete the
 * legally-significant line).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_category_id')->nullable()->after('product_id');

            $table->foreign('product_category_id', 'invoice_lines_product_category_fk')
                ->references('id')->on('product_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropForeign('invoice_lines_product_category_fk');
            $table->dropColumn('product_category_id');
        });
    }
};
