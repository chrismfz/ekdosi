<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();              // Filament tenant routing
            $t->string('afm', 20)->nullable();
            $t->string('tax_office', 60)->nullable();
            $t->string('address')->nullable();
            $t->string('city', 60)->nullable();
            $t->string('postcode', 10)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            // Per-tenant myDATA credentials (firebed/aade-mydata)
            $t->string('mydata_aade_id')->nullable();
            $t->text('mydata_subscription_key')->nullable();
            $t->boolean('mydata_production')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
