<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('qty', 9, 3)->default(1);
            $t->decimal('price_per_item', 14, 2)->nullable();
            $t->decimal('discount', 15, 4)->default(0);    // legacy BIG_NUMERIC
            $t->decimal('vat_percent', 5, 2)->nullable();
            $t->decimal('net_price', 14, 2)->nullable();   // legacy PRICE
            $t->decimal('gross_price', 14, 2)->nullable(); // legacy PRICEWVAT
            $t->string('product_descr', 256)->nullable();  // frozen description at issue time
            $t->string('metric_unit', 15)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
            $t->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
