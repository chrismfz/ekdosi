<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard link Payment → PaymentIntent (Πυλώνας B trail). A portal/gateway payment
 * is settled by writing Payment rows; until now they were tied to their intent
 * only by the shared `reference` string. This FK makes the trail explicit and
 * clickable both ways («πλήρωσα, δεν φαίνεται;» → intent → its Payment(s) → the
 * invoice), like credit-note → credited_invoice_id. Null = a payment not born of
 * an intent (operator-entered, FIFO έμβασμα, WHMCS sync, import…).
 *
 * Backfill: existing settled-intent payments already carry the intent's
 * (company_id, reference) — and payment_intents has a DB-enforced
 * unique(company_id, reference), so that pair maps to exactly one intent; we wire
 * the FK from it with no guessing and no ambiguity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->foreignId('payment_intent_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('payment_intents')
                ->nullOnDelete();
        });

        // Deterministic backfill via the shared reference (unique per company on
        // payment_intents), so no content-guessing — only exact matches wire up.
        DB::table('payment_intents')->orderBy('id')->chunkById(500, function ($intents): void {
            foreach ($intents as $intent) {
                DB::table('payments')
                    ->where('company_id', $intent->company_id)
                    ->where('reference', $intent->reference)
                    ->whereNull('payment_intent_id')
                    ->update(['payment_intent_id' => $intent->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('payment_intent_id');
        });
    }
};
