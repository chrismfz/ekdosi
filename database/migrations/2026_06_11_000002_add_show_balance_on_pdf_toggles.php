<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Υπόλοιπο πελάτη» on the invoice PDF — two opt-in knobs:
 *   • companies.show_customer_balance_on_pdf — per-tenant DEFAULT (off, B2B-y
 *     tenants flip it on).
 *   • customers.show_balance_on_pdf — per-customer OVERRIDE, nullable: null =
 *     inherit the tenant default, true/false = force on/off (a retail walk-in
 *     never wants a running balance; a key B2B account always does).
 *
 * Effective = customers.show_balance_on_pdf ?? companies.show_customer_balance_on_pdf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('show_customer_balance_on_pdf')->default(false)->after('pdf_footer_text');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('show_balance_on_pdf')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('show_customer_balance_on_pdf'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('show_balance_on_pdf'));
    }
};
