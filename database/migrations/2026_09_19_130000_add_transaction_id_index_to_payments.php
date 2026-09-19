<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `payments(company_id, transaction_id)`.
 *
 * PaymentIntentService::settle() looks a provider transaction up on this column
 * INSIDE the `lockForUpdate()` transaction (the "one acquirer transaction may
 * settle at most one intent" guard), so an unindexed lookup would scan the
 * company's payments while holding the intent row lock. Two existing callers
 * filter on the same column and benefit too: WhmcsPaymentSyncer and
 * `payments:apply-imported-credits`.
 *
 * Plain index, NOT unique: `transaction_id` is a shared free-text column (operator
 * forms, the Epsilon importer, the WHMCS syncer, and the ΠΛ- reference fallback
 * for manual settles all write it), so duplicates are legitimate there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['company_id', 'transaction_id'], 'payments_company_transaction_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_company_transaction_index');
        });
    }
};
