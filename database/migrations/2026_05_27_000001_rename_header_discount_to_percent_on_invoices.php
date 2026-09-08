<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename invoices.header_discount -> invoices.header_discount_percent and
 * tighten the type from decimal(14,2) to decimal(5,2).
 *
 * The original create_invoices_table migration carried the legacy column's
 * CURRENCY type even though every code path (legacy C++Builder forms +
 * stored procs CALCULATE_VAT_FOR_INVOICE etc.) treats the value as a
 * PERCENT 0-100. The misnamed column was a footgun for anyone reading the
 * schema cold — the legacy form even has 0 <= value <= 100 input
 * validation, which is incompatible with a decimal(14,2) currency range.
 *
 * Re-runnable ETL implication: MigrateFromFirebird.php's copyInvoices()
 * now writes to header_discount_percent (matching change in the ETL
 * command lands in the same commit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->renameColumn('header_discount', 'header_discount_percent');
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('header_discount_percent', 5, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('header_discount_percent', 14, 2)->default(0)->change();
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->renameColumn('header_discount_percent', 'header_discount');
        });
    }
};
