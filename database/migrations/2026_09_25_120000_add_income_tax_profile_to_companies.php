<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Φορολογικά» page — the per-tenant income-tax profile the estimate runs on
 * (App\Support\Accounting\IncomeTaxProfile): rate, prepayment rate and the
 * per-year ΒΕΒΑΙΩΜΕΝΗ προκαταβολή the operator copies from the actual assessment.
 * JSON, nullable: null = the defaults (22% / 80%, no assessed prepayment → it counts as 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('income_tax_profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('income_tax_profile');
        });
    }
};
