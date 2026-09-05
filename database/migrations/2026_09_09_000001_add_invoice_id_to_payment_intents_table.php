<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice-targeted portal payments (Πυλώνας B): a PaymentIntent may name the ONE
 * invoice the customer chose to pay, so settle() applies the money to THAT
 * document (capped at its balance, remainder on-account) instead of the default
 * FIFO-over-balance allocation. Null = the existing «pay my whole balance» flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $t): void {
            $t->foreignId('invoice_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('invoice_id');
        });
    }
};
