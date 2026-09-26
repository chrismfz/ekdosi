<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ΕΡΓΑΝΗ «Πέρασμα σε Παραγωγή» — who switched the company to REAL declarations
 * and when (the day the accountant stops declaring by hand). Null = never / back
 * in trial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('ergani_production_since')->nullable();
            $table->foreignId('ergani_production_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ergani_production_by_user_id');
            $table->dropColumn('ergani_production_since');
        });
    }
};
