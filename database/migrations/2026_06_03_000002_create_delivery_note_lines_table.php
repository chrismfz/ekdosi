<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lines of a Δελτίο Αποστολής — the items moving. Value-less twin of
 * `invoice_lines`: quantity + description + measurement unit, NO price/VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $t->decimal('qty', 9, 3)->default(1);
            $t->unsignedTinyInteger('measurement_unit')->nullable(); // §8.13 (myDATA code)
            $t->string('metric_unit', 15)->nullable();               // free-text fallback
            $t->unsignedTinyInteger('move_purpose_line')->nullable(); // optional per-line §8.14
            $t->string('product_descr', 256)->nullable();            // frozen at issue
            $t->text('notes')->nullable();

            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
            $t->index('delivery_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
    }
};
