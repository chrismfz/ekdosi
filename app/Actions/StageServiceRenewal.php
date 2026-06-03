<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ServiceContract;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stage a DRAFT renewal invoice for a due service contract.
 *
 * Mirrors IssueCreditNote / WhmcsInvoiceFiler::createDraft exactly: allocate
 * the ΑΑ under a row lock + persist the invoice + one line inside ONE
 * DB::transaction, then RecomputeInvoiceTotals. NO myDATA submit, NO mydata_*
 * writes, NO email — the draft flows through the normal invoice lifecycle
 * (Οριστικοποίηση → Υποβολή στο myDATA), so the operator reviews every
 * legally-significant document before it reaches AADE. The money lives on the
 * invoice via the normal InvoiceBalance path; this action never touches the
 * paid/credited/payment_status cache directly.
 *
 * next_due_date advancement (BillingCycle::advance) + last_invoiced_at are
 * stamped INSIDE the same transaction, so a rollback un-advances them — the
 * contract's billing clock and the invoice are committed atomically together.
 *
 * Idempotency: the contract carries a single next_due_date "cursor". We only
 * stage when it is on/before $asOf (the scopeDue predicate). After staging we
 * advance it past $asOf, so a second call in the same period finds nothing due
 * and returns null. As a belt-and-braces guard we also skip when an un-issued
 * draft already exists for this contract (a previous stage that the operator
 * hasn't issued/cancelled yet) — never two open drafts for one cursor.
 */
class StageServiceRenewal
{
    public function __invoke(ServiceContract $contract, ?Carbon $asOf = null): ?Invoice
    {
        $asOf ??= Carbon::today();

        $contract->loadMissing(['customer', 'company', 'invoiceType']);

        // LOUD refusal: never guess an invoice type. A contract without a
        // renewal type can't be billed — the operator must set it.
        if ($contract->invoice_type_id === null || $contract->invoiceType === null) {
            throw new InvalidArgumentException(
                'Το συμβόλαιο δεν έχει τύπο παραστατικού ανανέωσης (invoice_type_id). '
                .'Ορίστε τον τύπο παραστατικού πριν την έκδοση ανανέωσης.'
            );
        }

        // Idempotency (cursor): not due as of $asOf → nothing to stage.
        if ($contract->next_due_date === null
            || Carbon::parse($contract->next_due_date)->startOfDay()->gt($asOf->copy()->startOfDay())) {
            return null;
        }

        // Idempotency (belt-and-braces): an un-issued draft renewal already
        // exists for this contract — don't open a second one for the same
        // cursor. The operator must issue or cancel the open draft first.
        $existingDraft = Invoice::query()
            ->where('company_id', $contract->company_id)
            ->where('service_contract_id', $contract->id)
            ->where('local_status', 'draft')
            ->whereNull('mydata_mark')
            ->exists();
        if ($existingDraft) {
            return null;
        }

        $type = $contract->invoiceType;
        $customer = $contract->customer;

        return DB::transaction(function () use ($contract, $type, $customer, $asOf) {
            // Lock + RE-READ the contract row so two concurrent stage attempts
            // can't both read the same next_due_date and double-stage (TOCTOU
            // on the cursor). The pre-transaction checks above are a fast path;
            // these RE-CHECK the cursor + open-draft guard UNDER the lock, so
            // the second writer (which blocked on the lock) sees the advanced
            // cursor / the draft the first one just created and bails.
            $locked = ServiceContract::query()->whereKey($contract->id)->lockForUpdate()->first();
            if ($locked === null
                || $locked->next_due_date === null
                || Carbon::parse($locked->next_due_date)->startOfDay()->gt($asOf->copy()->startOfDay())) {
                return null;
            }
            $hasOpenDraft = Invoice::query()
                ->where('company_id', $contract->company_id)
                ->where('service_contract_id', $contract->id)
                ->where('local_status', 'draft')
                ->whereNull('mydata_mark')
                ->exists();
            if ($hasOpenDraft) {
                return null;
            }

            $allocation = app(InvoiceNumberer::class)->allocate($contract->company, $type->code);

            $invoice = Invoice::create([
                'company_id' => $contract->company_id,
                'invoice_type_id' => $type->id,
                'customer_id' => $contract->customer_id,
                'service_contract_id' => $contract->id,
                // Credit-term renewal stays a receivable (→ overdue/dunning):
                // contract's method, else the renewal type's default.
                'payment_method_id' => $contract->payment_method_id ?? $type->payment_method_id,
                'issued_at' => now(),
                'code' => $allocation->code,
                'invcode' => $allocation->invcode,
                'local_status' => 'draft',
                // Party snapshot copied from the contract's customer — same
                // shape as IssueCreditNote / WhmcsInvoiceMapper. Frozen on
                // the draft; the operator can still edit it until issued.
                'company_name' => $customer?->name,
                'vat_no' => $customer?->afm,
                'vies_vat' => $customer?->vat_vies,
                'occupation' => $customer?->occupation,
                'address1' => $customer?->address1,
                'address2' => $customer?->address2,
                'city' => $customer?->city,
                'postcode' => $customer?->postcode,
                'country' => $customer?->country ?: 'GR',
            ]);

            // One line from the contract snapshot. contract.amount is the
            // recurring price treated as the NET unit price; vat_percent is
            // the contract's snapshot rate. The InvoiceLine::saving hook
            // computes net_price/gross_price authoritatively.
            InvoiceLine::create([
                'company_id' => $contract->company_id,
                'invoice_id' => $invoice->id,
                'product_id' => $contract->product_id,
                'qty' => 1,
                'price_per_item' => $contract->amount,
                'discount' => 0,
                'vat_percent' => $contract->vat_percent,
                'product_descr' => $contract->description
                    ?: $contract->product?->description_short
                    ?: ('Ανανέωση συνδρομής'),
            ]);

            app(RecomputeInvoiceTotals::class)($invoice);

            // Advance the billing cursor + stamp the last-billed time INSIDE
            // the transaction so a rollback un-advances. advance() is null
            // for One-Time cycles — those don't recur, so we null the cursor
            // (a one-time contract bills exactly once).
            $next = $contract->billing_cycle?->advance(Carbon::parse($contract->next_due_date));
            $contract->forceFill([
                'next_due_date' => $next?->toDateString(),
                'last_invoiced_at' => now(),
            ])->save();

            return $invoice->refresh();
        });
    }
}
