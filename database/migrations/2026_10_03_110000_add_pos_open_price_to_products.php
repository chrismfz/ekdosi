<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ταμείο»: a generic «open-price» item (e.g. «ΡΟΥΧΑ 24%») — the cashier types the
 * price at the till for anything without its own code. Only products flagged here
 * accept a typed price; every other product sells at its catalogue price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pos_open_price')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pos_open_price');
        });
    }
};
