<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-2: a GROSS-anchored line (retail shelf price). When set, the line's money is
 * derived from this VAT-inclusive unit price (App\Support\LineMoney::fromGross) so
 * the line total is exactly the shelf price; null (every existing line) = the
 * classic net-anchored line from price_per_item. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('gross_unit_price', 14, 2)->nullable()->after('price_per_item');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('gross_unit_price');
        });
    }
};
