<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Φορολογικά»: one snapshot per (company, year) of AADE's own Ε3 aggregation
 * (RequestE3Info) — the accountant's FINAL classification, the source of the
 * income-tax estimate for closed years (local line classifications are the
 * original ones; a later re-classification, e.g. a 17.5 re-booked as αγορά
 * παγίου, only lives in myDATA). Refreshed on demand (page action / CLI), never
 * edited by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e3_year_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('income', 14, 2)->default(0);
            $table->decimal('expense', 14, 2)->default(0);   // deductible, without αγορές παγίων
            $table->decimal('capex', 14, 2)->default(0);     // αγορές παγίων (category2_7 / E3_882-883)
            $table->unsignedInteger('doc_count')->default(0);
            $table->json('rows')->nullable();                 // [{type, category, value, count}] as fetched
            $table->date('through');                          // window end (today for the running year)
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e3_year_snapshots');
    }
};
