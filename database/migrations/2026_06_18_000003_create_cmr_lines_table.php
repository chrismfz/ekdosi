<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods lines of a CMR (boxes 6–12). Self-contained snapshot so a standalone CMR
 * (no source document) works, and a sourced one keeps its own editable English
 * text. Value-less — a transport document carries no prices/VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmr_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cmr_note_id')->constrained()->cascadeOnDelete();

            $t->string('marks_numbers', 60)->nullable();   // box 6
            $t->unsignedInteger('packages_count')->nullable(); // box 7
            $t->string('packing_method', 40)->nullable();  // box 8
            $t->string('nature_en', 256)->nullable();      // box 9 (description, Latin)
            $t->string('statistical_no', 20)->nullable();  // box 10 (HS/commodity)
            $t->decimal('weight_kg', 9, 3)->nullable();    // box 11 gross weight
            $t->decimal('volume_m3', 9, 3)->nullable();    // box 12
            $t->string('adr_class', 10)->nullable();       // dangerous goods (usually blank)

            $t->timestamps();
            $t->index('cmr_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmr_lines');
    }
};
