<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-extends `payments` for per-invoice settlement + the payment
 * "way". All nullable so the ETL keeps re-importing legacy backups:
 * legacy PAYMENT rows have neither an invoice link nor a method, so
 * copyPayments() leaves both null → those payments land "on-account"
 * (customer-level), preserving the legacy GET_CUSTOMER_BALANCE math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('invoice_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
            $t->foreignId('payment_method_id')->nullable()->after('invoice_id')
                ->constrained()->nullOnDelete();
            $t->softDeletes();
            $t->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'invoice_id']);
            $t->dropConstrainedForeignId('invoice_id');
            $t->dropConstrainedForeignId('payment_method_id');
            $t->dropSoftDeletes();
        });
    }
};
