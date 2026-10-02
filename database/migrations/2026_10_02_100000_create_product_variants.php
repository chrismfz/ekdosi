<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product variants (docs/woocommerce-bridge-plan.md §0 «Θεμέλιο»).
 *
 * A variant (e.g. «Παντελόνι Nike — Μαύρο / M») is a REGULAR products row with
 * kind=variant + parent_product_id → its kind=variable parent. So stock, pricing,
 * invoice lines and myDATA keep working unchanged per variant; the parent is the
 * non-sellable grouping. Attributes (Χρώμα, Μέγεθος…) are per-tenant lookup rows
 * with an explicit sort, so grids/labels order S<M<L, 40<41 — not free json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('kind', 16)->default('simple')->after('company_id');   // simple|variable|variant
            $table->foreignId('parent_product_id')->nullable()->after('kind')
                ->constrained('products')->restrictOnDelete();
            $table->string('internal_code', 60)->nullable()->after('sku');      // e.g. the SoftOne item code
            $table->index(['company_id', 'kind']);
            $table->unique(['company_id', 'internal_code']);
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('kind', 16)->default('other');                      // color|size|other
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value', 60);
            $table->string('code', 12)->nullable();                            // SKU suffix, e.g. BLK / 42
            $table->char('color_hex', 7)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['product_attribute_id', 'value']);
        });

        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_attribute_value_id')->constrained()->restrictOnDelete();

            $table->timestamps();   // the company export/import stamps every row

            // One value per attribute per variant.
            $table->unique(['product_id', 'product_attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'internal_code']);
            $table->dropIndex(['company_id', 'kind']);
            $table->dropConstrainedForeignId('parent_product_id');
            $table->dropColumn(['kind', 'internal_code']);
        });
    }
};
