<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Log πύλης» — a durable, operator-visible record of EVERY inbound gateway
 * notification (a vPOS return today). The debugging tool for «πλήρωσα, δεν
 * φαίνεται»: instead of grepping laravel.log you SEE that the return arrived,
 * what its signed status was, whether the digest verified, and — if it was NOT
 * settled — exactly why (digest/amount/currency mismatch, unknown order…).
 *
 * Write-once audit rows; never mutated. Best-effort (a logging hiccup must never
 * break settlement), so no FK hard-constraints to the intent (store the id +
 * order id as plain values — the intent may be gone/soft-deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('payment_intent_id')->nullable();   // soft ref (no FK: best-effort log)
            $t->string('gateway', 40);                                  // registry key: 'eurobank'…
            $t->string('order_id', 64)->nullable();                     // vPOS orderid (= intent id / «Ekdosi #N»)
            $t->string('outcome', 32);                                  // 'settled' | 'ignored' | 'rejected'
            $t->string('reason', 64)->nullable();                       // reject/ignore reason code
            $t->boolean('verified')->default(false);                    // did the provider digest verify?
            $t->string('provider_status', 40)->nullable();              // raw acquirer status (CAPTURED…)
            $t->string('transaction_id', 64)->nullable();               // acquirer txn id («ID Συναλλαγής»)
            $t->decimal('amount', 14, 2)->nullable();
            $t->string('currency', 8)->nullable();
            $t->string('ip', 45)->nullable();
            $t->text('message')->nullable();                            // acquirer message, if any
            $t->timestamps();

            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'payment_intent_id']);
            $t->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_events');
    }
};
