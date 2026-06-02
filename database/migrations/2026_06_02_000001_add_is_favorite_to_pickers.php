<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-feedback polish: "favourites" on the three high-traffic
 * invoice-form pickers (invoice types, customers, products).
 *
 * A plain per-row boolean (NOT tags) — the accountant's need is "the
 * 2-3 things I always pick should sit on top". Combined at query time
 * with an auto-top order (usage count / invcount), so the picker shows
 * favourites first, then the most-used, then the rest. Tenant scoping
 * rides on the existing company_id; the index keeps the favourites-first
 * ORDER BY cheap on large customer/product catalogues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_types', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('show_on_menu');
            $table->index(['company_id', 'is_favorite']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('is_active');
            $table->index(['company_id', 'is_favorite']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('is_active');
            $table->index(['company_id', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_types', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_favorite']);
            $table->dropColumn('is_favorite');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_favorite']);
            $table->dropColumn('is_favorite');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_favorite']);
            $table->dropColumn('is_favorite');
        });
    }
};
