<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Support\InvoiceScope;
use App\Support\Payments\PaymentInitiation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestrates a payment intent: START it (portal) and SETTLE it (operator
 * confirmation for `manual`; a signed webhook will call the same settle() in B1).
 * The money itself goes through the existing PaymentAllocator/InvoiceBalance — a
 * gateway payment is just a Payment. See docs/payment-gateways-design.md §5–6.
 */
class PaymentIntentService
{
    public function __construct(
        private PaymentGatewayRegistry $registry,
        private PaymentAllocator $allocator,
    ) {}

    /**
     * Create a pending intent and ask the gateway where to send the customer.
     * The amount is the customer's choice (their money); for `manual` the operator
     * confirms the ACTUAL amount received at settle time.
     *
     * @return array{intent: PaymentIntent, initiation: PaymentInitiation}
     */
    public function start(
        Customer $customer,
        PaymentGatewayConnection $connection,
        float $amount,
        ?CustomerUser $login = null,
        string $purpose = 'balance',
        ?Invoice $invoice = null,
    ): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Το ποσό πληρωμής πρέπει να είναι θετικό.');
        }
        if (! $connection->is_active) {
            throw new RuntimeException('Ο τρόπος πληρωμής δεν είναι ενεργός.');
        }
        // The connection must belong to the SAME company as the customer — a guard
        // against a mismatched (company, method) pairing.
        if ((int) $connection->company_id !== (int) $customer->company_id) {
            throw new RuntimeException('Ο τρόπος πληρωμής δεν ανήκει στην εταιρία του πελάτη.');
        }
        // A targeted invoice must be THIS customer's AND this company's (never trust
        // a caller-supplied invoice id across the tenant/customer boundary — checking
        // both, not just customer_id, keeps the guard honest even if id spaces ever
        // overlap). Payability is (re)checked at settle, which safely falls back to
        // FIFO — so a stale target never strands money.
        if ($invoice !== null && ((int) $invoice->customer_id !== (int) $customer->id
            || (int) $invoice->company_id !== (int) $customer->company_id)) {
            throw new RuntimeException('Το παραστατικό δεν ανήκει στον πελάτη.');
        }

        $gateway = $this->registry->for($connection->gateway);
        if (! $gateway->capabilities()->chargeable()) {
            throw new RuntimeException('Αυτός ο τρόπος πληρωμής δεν μπορεί να δεχτεί πληρωμή.');
        }

        $intent = new PaymentIntent([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'customer_user_id' => $login?->id,
            'gateway' => $connection->gateway,
            // Remember the method → its config (shared secret) so the online return
            // webhook can verify the provider digest against the right connection.
            'payment_gateway_connection_id' => $connection->id,
            'purpose' => $invoice !== null ? 'invoice' : $purpose,
            'amount' => $amount,
            'currency' => 'EUR',
            'status' => PaymentIntent::STATUS_PENDING,
            'reference' => $this->reference($customer->company_id),
        ]);
        $intent->save();

        $initiation = $gateway->initiate($intent, $connection);

        // Snapshot the offline instructions so the «how to pay» page is stable even
        // if the operator later edits the method's config.
        if ($initiation->flow === 'offline') {
            $intent->forceFill([
                'instructions' => trim((string) $initiation->bankDetails."\n\n".(string) $initiation->instructions) ?: null,
            ])->save();
        }

        return ['intent' => $intent, 'initiation' => $initiation];
    }

    /**
     * Settle a pending intent → write the Payment(s) via PaymentAllocator (FIFO
     * over open invoices + an on-account remainder), sharing the intent's
     * reference so the Καρτέλα groups them. IDEMPOTENT: a locked pending→settled
     * transition, so a double confirmation (or a replayed webhook, B1) can never
     * create two payments. `$actualAmount` lets the operator record what was truly
     * received (a manual deposit may differ from the intended amount).
     */
    public function settle(
        PaymentIntent $intent,
        string $settledBy,
        ?float $actualAmount = null,
        ?int $paymentMethodId = null,
        ?string $transactionId = null,
    ): void {
        DB::transaction(function () use ($intent, $settledBy, $actualAmount, $paymentMethodId, $transactionId): void {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()
                ->withoutGlobalScope(CompanyScope::class)   // context-independent (operator now, webhook in B1)
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            // Settle a PENDING intent, OR one auto-EXPIRED by the stale-intent sweep:
            // a genuine (verified) capture that arrives after our own expiry guess
            // must still be recorded — money truth overrides the sweep. A human
            // CANCELLED or an already-SETTLED intent stays a no-op (idempotent).
            if (! in_array($locked->status, [PaymentIntent::STATUS_PENDING, PaymentIntent::STATUS_EXPIRED], true)) {
                return;
            }

            $customer = Customer::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $locked->company_id)
                ->whereKey($locked->customer_id)
                ->firstOrFail();

            $amount = round($actualAmount ?? (float) $locked->amount, 2);

            // Auto-stamp the myDATA «Τρόπος πληρωμής»: an explicit one (operator form)
            // wins; otherwise inherit the channel's configured default (e.g. Eurobank
            // → «Ηλεκτρονικά μέσα Πληρωμών») so a gateway payment isn't left method-less.
            $methodId = $paymentMethodId ?? $this->connectionMethodId($locked);

            // Provenance («πώς ήρθε η συναλλαγή»): the Payment IS the transactions
            // ledger. `reference` stays our ΠΛ- receipt key (groups the είσπραξη in
            // the Καρτέλα); `transaction_id` carries the ACQUIRER's txn id when the
            // gateway reported one (falls back to our reference for manual), and the
            // gateway + txn id go into the notes for a human-readable trail.
            $notes = 'Πληρωμή μέσω: '.$locked->gateway;
            if (filled($transactionId)) {
                $notes .= ' (κωδ. συναλλαγής: '.$transactionId.')';
            }

            // Invoice-targeted intent → pay THAT invoice (capped, remainder on-account).
            // If the chosen invoice is no longer a payable target at settle time
            // (cancelled/credited/deleted between start and settle), we must NEVER
            // strand the captured money — fall back to the balance FIFO allocation.
            $target = $locked->invoice_id !== null ? $this->payableTarget($locked) : null;

            if ($target !== null) {
                $this->allocator->allocateToInvoice(
                    customer: $customer,
                    invoice: $target,
                    amount: $amount,
                    date: Carbon::now(),
                    paymentMethodId: $methodId,
                    reference: $locked->reference,
                    notes: $notes,
                    transactionId: $transactionId ?: $locked->reference,
                    paymentIntentId: $locked->id,
                );
            } else {
                $this->allocator->allocate(
                    customer: $customer,
                    amount: $amount,
                    date: Carbon::now(),
                    paymentMethodId: $methodId,
                    reference: $locked->reference,
                    notes: $notes,
                    transactionId: $transactionId ?: $locked->reference,
                    paymentIntentId: $locked->id,
                );
            }

            $locked->forceFill([
                'status' => PaymentIntent::STATUS_SETTLED,
                'settled_at' => Carbon::now(),
                'settled_by' => $settledBy,
            ])->save();
        });
    }

    /** The myDATA payment-method the intent's channel is configured to stamp, or null. */
    private function connectionMethodId(PaymentIntent $intent): ?int
    {
        if ($intent->payment_gateway_connection_id === null) {
            return null;
        }

        $methodId = PaymentGatewayConnection::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('company_id', $intent->company_id)
            ->whereKey($intent->payment_gateway_connection_id)
            ->value('payment_method_id');

        return $methodId !== null ? (int) $methodId : null;
    }

    /**
     * The intent's targeted invoice IF it is still a payable target at settle time
     * (this customer's, live, issued/active, not a credit note), else null so the
     * caller falls back to FIFO — the captured money is recorded either way.
     */
    private function payableTarget(PaymentIntent $intent): ?Invoice
    {
        $q = InvoiceScope::live(Invoice::query())
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $intent->company_id)
            ->where('customer_id', $intent->customer_id)
            ->where('local_status', 'active')
            ->whereKey($intent->invoice_id);
        InvoiceScope::excludeCreditNotes($q);

        return $q->first();
    }

    /**
     * Cancel a PENDING intent (a locked pending→cancelled transition), so it can
     * never race a concurrent settle(): if the intent was already settled (a
     * Payment written), cancel is a no-op and the settled state stands.
     */
    public function cancel(PaymentIntent $intent): void
    {
        DB::transaction(function () use ($intent): void {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if (! $locked->isPending()) {
                return;   // already settled/cancelled/expired — leave it
            }
            $locked->forceFill(['status' => PaymentIntent::STATUS_CANCELLED])->save();
        });
    }

    /** Unique-per-company allocation/receipt key. */
    private function reference(int $companyId): string
    {
        do {
            $ref = 'ΠΛ-'.now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (PaymentIntent::query()
            ->where('company_id', $companyId)
            ->where('reference', $ref)
            ->exists());

        return $ref;
    }
}
