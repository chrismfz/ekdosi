<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment intents (Πυλώνας B / B0b) — docs/payment-gateways-design.md §5.
 *
 * One row per «the customer set out to pay X through gateway G». It is created
 * when the customer starts a payment in the portal and is the anchor the outcome
 * settles against — the browser return NEVER settles money; only an operator
 * confirmation (manual) or, later, a signed webhook does. `status`: pending →
 * settled (operator confirm) | cancelled (operator). Settlement is idempotent on
 * the row (a locked pending→settled transition writes the Payment(s) exactly once).
 * `expired` + `expires_at` are reserved for the B1 online flow (an abandoned
 * redirect times out); a manual intent has no timeout — operators cancel stale ones.
 *
 * `reference` (unique per company) is the human/allocation key: it is written on
 * the resulting Payment rows so the Καρτέλα groups them as one «είσπραξη».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Who started it (a portal login) — null for an operator-created intent.
            $t->foreignId('customer_user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('gateway', 40);                 // 'manual' | 'stripe' … (registry key)
            $t->string('purpose', 20)->default('balance');   // 'balance' (B0b) | 'invoice' | 'topup' (later)
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3)->default('EUR');
            $t->string('status', 20)->default('pending');     // pending | settled | expired | cancelled
            $t->string('reference', 60);               // allocation/receipt key (written on the Payments)
            $t->text('instructions')->nullable();      // snapshot of offline instructions shown to the customer
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->string('settled_by', 60)->nullable();  // operator email / 'webhook' — audit of who settled
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference']);
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
