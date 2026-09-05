<?php

namespace App\Console\Commands;

use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Console\Command;

/**
 * Expire ABANDONED pending portal payment intents (Πυλώνας B hygiene). A customer
 * who bounces to a hosted gateway page and never completes leaves a `pending`
 * PaymentIntent forever — it clutters «Εκκρεμείς Πληρωμές Πύλης» and (per the
 * duplicate-attempt case) sits next to the one that DID settle. This sweep marks
 * the old ones `expired`.
 *
 * ONLINE gateways only. An OFFLINE (bank-deposit / manual) intent is the operator's
 * worklist — the customer said «θα καταθέσω», the operator settles when the money
 * actually arrives, which can be days — so those are NEVER auto-expired.
 *
 * Safe against a late settlement: PaymentIntentService::settle() accepts an
 * EXPIRED intent too, so a genuine (verified) capture that arrives after this
 * guess is still recorded — money truth overrides the sweep. Cross-tenant,
 * idempotent, non-destructive (a status flip, no rows deleted).
 */
class ExpireStalePaymentIntents extends Command
{
    protected $signature = 'payments:expire-stale-intents {--minutes= : Όριο ηλικίας (default: config ekdosi.payments.intent_expiry_minutes)} {--dry-run : Μόνο μέτρηση, χωρίς αλλαγή}';

    protected $description = 'Λήγει εγκαταλελειμμένα εκκρεμή online PaymentIntents της πύλης πέρα από το όριο ηλικίας.';

    public function handle(PaymentGatewayRegistry $registry): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('ekdosi.payments.intent_expiry_minutes', 120));
        if ($minutes < 1) {
            $this->error('Το όριο (--minutes) πρέπει να είναι θετικό.');

            return self::INVALID;
        }

        $cutoff = now()->subMinutes($minutes);

        // Offline gateways = operator worklist → never auto-expire.
        $offlineKeys = collect($registry->all())
            ->filter(fn ($g): bool => $g->capabilities()->flow === 'offline')
            ->map(fn ($g): string => $g->key())
            ->values()
            ->all();

        $query = PaymentIntent::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('status', PaymentIntent::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->when($offlineKeys !== [], fn ($q) => $q->whereNotIn('gateway', $offlineKeys));

        if ($this->option('dry-run')) {
            $this->info("Θα έληγαν {$query->count()} εκκρεμή online intents παλαιότερα των {$minutes}′.");

            return self::SUCCESS;
        }

        // Guarded bulk flip: the WHERE status='pending' means a concurrent settle
        // (which locks the row and flips it to 'settled' first) is never clobbered.
        $expired = $query->update([
            'status' => PaymentIntent::STATUS_EXPIRED,
            'updated_at' => now(),
        ]);

        $this->info("Έληξαν {$expired} εκκρεμή online intents παλαιότερα των {$minutes}′.");

        return self::SUCCESS;
    }
}
