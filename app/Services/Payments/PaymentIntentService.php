<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
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

        $gateway = $this->registry->for($connection->gateway);
        if (! $gateway->capabilities()->chargeable()) {
            throw new RuntimeException('Αυτός ο τρόπος πληρωμής δεν μπορεί να δεχτεί πληρωμή.');
        }

        $intent = new PaymentIntent([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'customer_user_id' => $login?->id,
            'gateway' => $connection->gateway,
            'purpose' => $purpose,
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
    ): void {
        DB::transaction(function () use ($intent, $settledBy, $actualAmount, $paymentMethodId): void {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::query()->whereKey($intent->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                return;   // already settled/expired/cancelled — no-op (idempotent)
            }

            $customer = Customer::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $locked->company_id)
                ->whereKey($locked->customer_id)
                ->firstOrFail();

            $amount = round($actualAmount ?? (float) $locked->amount, 2);

            $this->allocator->allocate(
                customer: $customer,
                amount: $amount,
                date: Carbon::now(),
                paymentMethodId: $paymentMethodId,
                reference: $locked->reference,
                notes: 'Πληρωμή μέσω: '.$locked->gateway,
                transactionId: $locked->reference,
            );

            $locked->forceFill([
                'status' => PaymentIntent::STATUS_SETTLED,
                'settled_at' => Carbon::now(),
                'settled_by' => $settledBy,
            ])->save();
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
