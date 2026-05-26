<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_categories.markup was created as decimal(14,2) (mirroring the
 * legacy CURRENCY domain). In practice the column is consumed as a
 * percentage — the new ProductCategoryForm renders it with a "%" suffix
 * and minValue(0), and the legacy FAddProduct.cpp sell-price computation
 * applies it as a multiplier: sell_price = buy_price * (1 + markup/100).
 *
 * Per the CLAUDE.md convention (and the prior fix to
 * invoices.header_discount_percent) percent fields are decimal(5,2).
 * Widening to 14,2 leaves room for nonsense values from ETL / future
 * API clients (e.g. markup=1000000.50 misread as an absolute amount)
 * that would overflow downstream PRICEWVAT decimal(14,2) calculations.
 *
 * Re-runnable: ALTER COLUMN with a stricter type is idempotent in
 * MariaDB; existing data fitting 5,2 stays put.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->decimal('markup', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->decimal('markup', 14, 2)->nullable()->change();
        });
    }
};
