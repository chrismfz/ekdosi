<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conf_params', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('varname', 60);
            $t->integer('data_int')->nullable();
            $t->string('data_string', 120)->nullable();
            $t->timestamp('data_timestamp')->nullable();
            $t->float('data_float')->nullable();
            $t->decimal('data_numeric', 15, 3)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'varname']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conf_params');
    }
};
