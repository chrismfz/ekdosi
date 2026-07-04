<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-5 (AUDIT): per-line myDATA E3 income classification for MIXED
 * goods+services invoices. The class was InvoiceType-scoped (one per document),
 * so a mixed invoice filed every line under one E3 code. The goods↔services
 * split is really a property of WHAT is sold, so the override lives on the
 * product CATEGORY (Εμπορεύματα→goods, Υπηρεσίες→services, Προϊόντα→products);
 * a line resolves product → category → these fields, falling back to the invoice
 * type's default. Nullable — an unset category inherits the type default, so
 * services-only tenants are unaffected.
 *
 * Mirrors the InvoiceType columns (string 30) so the same §8.8/§8.9 codes fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->string('mydata_income_class', 30)->nullable()->after('markup');
            $t->string('mydata_income_class_category', 30)->nullable()->after('mydata_income_class');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->dropColumn(['mydata_income_class', 'mydata_income_class_category']);
        });
    }
};
