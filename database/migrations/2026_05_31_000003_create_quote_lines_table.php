<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Quote lines — mirror the editable subset of invoice_lines (same per-line
     * net/gross math), minus the myDATA classification columns. A line may
     * reference a Product (προϊόν/υπηρεσία) or be pure free text
     * (product_id null + product_descr) — covers "προϊόν που δεν υπάρχει".
     */
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_descr')->nullable();
            $table->decimal('qty', 9, 3)->default(1);
            $table->decimal('price_per_item', 14, 2)->default(0);
            $table->decimal('discount', 5, 2)->default(0);
            $table->decimal('vat_percent', 5, 2)->default(0);
            $table->decimal('net_price', 14, 2)->default(0);
            $table->decimal('gross_price', 14, 2)->default(0);
            $table->string('metric_unit')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
