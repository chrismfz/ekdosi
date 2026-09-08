<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line income-classification snapshot (MYD-006 follow-up). Until now the §8.6
 * bucket was resolved at filing from the product category → invoice-type default →
 * business policy. That leaves no home for a bucket that belongs to a SPECIFIC
 * line without a product — the WHMCS-bridge case, where a free-text hosting line
 * should file under the group's mapped classification.
 *
 * These nullable columns let a line carry its OWN (E3 class, §8.6 category), which
 * `AadeInvoiceDocument::resolveIncomeClass` reads FIRST. Null = unchanged behaviour
 * (product/type/policy resolution), so every existing line and manual invoice is
 * untouched. Mirrors the product-category override pair (`mydata_income_class` +
 * `mydata_income_class_category`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('mydata_income_class', 32)->nullable()->after('vat_exemption_category');
            $table->string('mydata_income_class_category', 32)->nullable()->after('mydata_income_class');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['mydata_income_class', 'mydata_income_class_category']);
        });
    }
};
