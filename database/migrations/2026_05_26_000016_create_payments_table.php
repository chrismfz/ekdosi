<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->date('pay_date')->nullable();
            $t->decimal('amount', 14, 2)->nullable();   // legacy "VALUE"
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
            $t->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
