<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product reorder level. When on-hand ≤ this (and > 0), the «Απόθεμα» badge
 * turns orange + the «χαμηλό» filter catches it — so you reorder BEFORE hitting
 * zero. null/0 = no threshold (only negative is flagged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('reorder_level', 12, 3)->nullable()->after('track_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('reorder_level');
        });
    }
};
