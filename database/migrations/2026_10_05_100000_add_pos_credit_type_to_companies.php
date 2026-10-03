<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ταμείο» returns/exchanges (POS PR 2a): the retail credit-note series (11.4) a till
 * return is issued on. Null = returns are not available at the till.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('pos_credit_type_id')->nullable()->after('pos_invoice_type_id')
                ->constrained('invoice_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_credit_type_id');
        });
    }
};
