<?php

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Enums\ServiceContractStatus;
use App\Models\InvoiceType;
use App\Models\Quote;
use App\Models\ServiceContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Μετατροπή σε Υπηρεσία» — turn an accepted Προσφορά into a recurring service
 * contract PLUS the first draft invoice.
 *
 * Two outputs from one click (the model the operator expects):
 *   1. A first DRAFT invoice with ALL the quote's lines (one-time products,
 *      free-text AND the recurring product, first period) — produced via the
 *      existing ConvertQuoteToInvoice, so the very first παραστατικό shows
 *      everything the customer bought, with real descriptions.
 *   2. A ServiceContract for the RECURRING part only — it drives FUTURE
 *      renewals (which carry only the recurring line, no setup/one-offs).
 *
 * The cursor (next_due_date) starts at the contract's start date, so issuing
 * that first draft (draft→active) advances it one cycle via InvoiceObserver —
 * the first invoice IS period 1; the next renewal is period 2. No money/AADE
 * here: the draft flows through the normal lifecycle, the contract is not money.
 *
 * Idempotent: refuses if the quote was already converted (to an invoice OR a
 * service — both set converted_invoice_id).
 */
class ConvertQuoteToServiceContract
{
    public function __construct(private readonly ConvertQuoteToInvoice $convertToInvoice) {}

    public function __invoke(
        Quote $quote,
        InvoiceType $invoiceType,
        BillingCycle $cycle,
        float $recurringAmount,
        ?int $paymentMethodId = null,
        ?Carbon $startDate = null,
    ): ServiceContract {
        if ($quote->isConverted() || $quote->isConvertedToService()) {
            throw new RuntimeException('Η προσφορά έχει ήδη μετατραπεί.');
        }
        if ($invoiceType->company_id !== $quote->company_id) {
            throw new RuntimeException('Ο τύπος παραστατικού ανήκει σε άλλη εταιρεία.');
        }
        if ($quote->status !== QuoteStatus::Accepted) {
            throw new RuntimeException('Μόνο αποδεκτή προσφορά μετατρέπεται σε υπηρεσία.');
        }
        // Leads L1: service_contracts.customer_id is NOT NULL — same rule as the
        // invoice path, checked BEFORE the transaction (a Greek message, not SQL).
        if ($quote->isAwaitingLeadConversion()) {
            throw new RuntimeException(Quote::AWAITING_LEAD_MESSAGE);
        }
        if ($recurringAmount <= 0) {
            throw new RuntimeException('Το επαναλαμβανόμενο ποσό πρέπει να είναι θετικό.');
        }
        if (! $cycle->isRecurring()) {
            throw new RuntimeException('Επιλέξτε κύκλο χρέωσης (όχι «Εφάπαξ») για υπηρεσία.');
        }

        $quote->loadMissing('lines.product');
        $start = ($startDate ?? Carbon::today())->startOfDay();
        $recurringLine = $quote->firstRecurringLine();

        return DB::transaction(function () use ($quote, $invoiceType, $cycle, $recurringAmount, $paymentMethodId, $start, $recurringLine) {
            // Lock + re-check under the row lock BEFORE creating the contract, so
            // a concurrent double-submit can't produce two contracts/invoices for
            // one quote (the loser blocks, then sees the flags set and bails — its
            // contract never commits). ConvertQuoteToInvoice below re-locks the
            // same row in this transaction (re-entrant, fine).
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->first();
            if ($locked === null
                || $locked->converted_invoice_id !== null
                || $locked->converted_service_contract_id !== null) {
                throw new RuntimeException('Η προσφορά έχει ήδη μετατραπεί.');
            }

            // The contract drives FUTURE renewals (recurring part only). amount is
            // the per-cycle net charge; cursor at start so the first invoice's
            // issue advances it one cycle (period 1 → period 2).
            $contract = ServiceContract::create([
                'company_id' => $quote->company_id,
                'customer_id' => $quote->customer_id,
                'product_id' => $recurringLine?->product_id,
                'invoice_type_id' => $invoiceType->id,
                'payment_method_id' => $paymentMethodId,
                'description' => $recurringLine?->product_descr
                    ?: $recurringLine?->product?->description_short
                    ?: 'Συνδρομή',
                'billing_cycle' => $cycle->value,
                'quantity' => 1,
                'amount' => round($recurringAmount, 2),
                'vat_percent' => $recurringLine?->vat_percent ?? 0,
                'status' => ServiceContractStatus::Active->value,
                'start_date' => $start->toDateString(),
                'next_due_date' => $start->toDateString(),
                'provisioning_module' => $recurringLine?->product?->provisioning_module ?? 'none',
            ]);

            // First invoice = the full quote (all lines), linked to the contract.
            $invoice = ($this->convertToInvoice)($quote, $invoiceType);
            $invoice->update(['service_contract_id' => $contract->id]);

            // Provenance (the invoice link is already set by ConvertQuoteToInvoice).
            $quote->forceFill(['converted_service_contract_id' => $contract->id])->save();

            return $contract->refresh();
        });
    }
}
