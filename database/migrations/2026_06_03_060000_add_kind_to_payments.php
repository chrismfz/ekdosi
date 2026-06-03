<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direction of a money row: 'payment' (money IN from the customer, the default
 * — every existing row) or 'refund' (money OUT, back to the customer). A refund
 * is stored with a POSITIVE amount but the money math subtracts it from the
 * invoice's / customer's paid total (so it raises the balance again). One ledger,
 * explicit semantics — no negative amounts to surprise aggregates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->enum('kind', ['payment', 'refund'])->default('payment')->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn('kind');
        });
    }
};
