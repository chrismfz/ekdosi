<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// legacy PROD_PRICE_QTY: quantity-break pricing per product
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->decimal('value', 14, 2)->nullable();         // legacy VAL (reserved word avoided)
            $t->decimal('discount_percent', 5, 2)->nullable();
            $t->decimal('qty', 9, 3)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
