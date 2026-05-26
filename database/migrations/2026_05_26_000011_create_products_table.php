<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->string('barcode', 25)->nullable();
            $t->string('description_short', 120);
            $t->text('description')->nullable();
            $t->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('vat_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('metric_unit_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('buy_price', 14, 2)->default(0);
            $t->decimal('sell_price', 14, 2)->default(0);
            $t->decimal('price_wvat', 14, 2)->default(0);
            $t->decimal('reserve', 7, 3)->default(0);
            $t->decimal('reserve_secure', 7, 3)->default(0);
            $t->date('date_inserted')->nullable();
            $t->timestamps();   // legacy LAST_UPDATE maps to updated_at
            $t->softDeletes();
            $t->unique(['company_id', 'legacy_id']);
            $t->unique(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
