<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External transaction reference for a payment — a Stripe `pi_…`, a PayPal txn
 * id, or a bank wire reference. The `payment_method` already says HOW (card /
 * web banking / IRIS / cash…); this says WHICH transaction. For an «έμβασμα»
 * that settles several invoices it's the same id on every row of the group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('transaction_id', 100)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn('transaction_id');
        });
    }
};
