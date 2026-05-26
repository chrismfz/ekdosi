<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_invoice_extras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_line_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty_given', 9, 3)->nullable();
            $t->decimal('qty_returned', 9, 3)->nullable();
            $t->decimal('qty_sent', 9, 3)->nullable();
            $t->timestamps();
            $t->unique('invoice_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_invoice_extras');
    }
};
