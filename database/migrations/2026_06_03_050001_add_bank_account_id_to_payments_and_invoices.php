<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a payment to the bank account the money landed in, and an invoice to the
 * deposit account printed on it (for «πληρωμή σε τράπεζα/έμβασμα»). Both
 * nullable — cash/POS payments and invoices without a transfer have none.
 * `nullOnDelete` so retiring an account never deletes money rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('bank_account_id')->nullable()->after('payment_method_id')
                ->constrained('bank_accounts')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('bank_account_id')->nullable()->after('payment_method_id')
                ->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bank_account_id');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bank_account_id');
        });
    }
};
