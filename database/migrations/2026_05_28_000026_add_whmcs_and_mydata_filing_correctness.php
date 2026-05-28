<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS + myDATA filing-correctness gaps G3 / G9 / G5.
 *
 * - companies.whmcs_amount_includes_tax (G3) — whether a WHMCS instance sends
 *   line amounts GROSS (VAT-inclusive, the Greek norm + current assumption) or
 *   NET (tax-exclusive). Default TRUE preserves today's behaviour; a
 *   tax-exclusive tenant flips it so WhmcsInvoiceMapper stops dividing out a
 *   VAT that isn't in the amount.
 *
 * - payment_methods.mydata_payment_type (G9) — the AADE §8.12 payment-method
 *   type (1–8) this method maps to. Null → the submitter falls back to 3 (cash).
 *   Replaces the hardcoded `return 3` so transfer/card/credit file correctly.
 *
 * - invoice_types.mydata_requires_quantity (G5) — goods invoice types take a
 *   per-line <quantity>; service types ([205]) forbid it. Default FALSE = the
 *   sandbox-validated service path (no quantity); a goods type opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('whmcs_amount_includes_tax')
                ->default(true)
                ->after('whmcs_third_party_enabled');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unsignedTinyInteger('mydata_payment_type')->nullable();
        });

        Schema::table('invoice_types', function (Blueprint $table) {
            $table->boolean('mydata_requires_quantity')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whmcs_amount_includes_tax');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('mydata_payment_type');
        });

        Schema::table('invoice_types', function (Blueprint $table) {
            $table->dropColumn('mydata_requires_quantity');
        });
    }
};
