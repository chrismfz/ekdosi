<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->string('description', 120)->nullable();
            $t->decimal('rate', 5, 2)->default(0);     // legacy VAT_CATEGORY.VALUE (the % rate)
            $t->text('long_description')->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_categories');
    }
};
