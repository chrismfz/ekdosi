<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in stock tracking per product. Services (and any product the operator
 * doesn't want counted) leave this false — the whole stock engine ignores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('track_stock')->default(false)->after('is_favorite');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('track_stock');
        });
    }
};
