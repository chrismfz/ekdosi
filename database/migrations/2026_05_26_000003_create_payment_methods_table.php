<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->string('description', 120)->nullable();
            $t->integer('due_days')->nullable();      // >0 => credit, counts toward balance
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
