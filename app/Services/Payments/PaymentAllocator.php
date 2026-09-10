<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Support\InvoiceScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Allocates one received amount («έμβασμα/είσπραξη») across a customer's open
 * invoices, oldest-first (FIFO) — and parks any remainder as an on-account
 * credit (a Payment with invoice_id = null). Each invoice gets at most its own
 * balance; the last touched invoice may end up partially paid. Everything is
 * created as ordinary Payment rows (so PaymentObserver recomputes each invoice's
 * cache) sharing one `reference` so the Καρτέλα can group them — NO change to
 * InvoiceBalance / the money model.
 *
 *   €1200 over invoices of €1500  → settles the oldest, last one partial, €300 still owed.
 *   €2000 over invoices of €1500  → settles all + €500 on-account credit.
 */
class PaymentAllocator
{
    public function allocate(
        Customer $customer,
        float $amount,
        Carbon $date,
        ?int $paymentMethodId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $transactionId = null,
        ?int $bankAccountId = null,
        ?int $paymentIntentId = null,
    ): PaymentAllocationResult {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Το ποσό της είσπραξης πρέπει να είναι θετικό.');
        }

        $ref = $reference ?: 'ΕΙΣ-'.now()->format('YmdHis').'-'.substr(uniqid(), -4);

        return DB::transaction(function () use ($customer, $amount, $date, $paymentMethodId, $ref, $notes, $transactionId, $bankAccountId, $paymentIntentId) {
            $remaining = $amount;
            $allocations = [];

            // The FIFO target set (shared with absorbableTotal so a guard/preview
            // can never disagree with this write path). Cash-term & already-paid
            // invoices have balance 0 → skipped below. A draft's amount flows to
            // the on-account remainder instead.
            $open = $this->openInvoicesQuery($customer)->get();

            foreach ($open as $invoice) {
                if ($remaining <= 0.005) {
                    break;
                }
                $balance = round((float) $invoice->balanceData()->balance, 2);
                if ($balance <= 0.005) {
                    continue;
                }
                $pay = round(min($balance, $remaining), 2);

                Payment::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice->id,
                    'payment_intent_id' => $paymentIntentId,
                    'kind' => 'payment',
                    'payment_method_id' => $paymentMethodId,
                    'bank_account_id' => $bankAccountId,
                    'pay_date' => $date->toDateString(),
                    'amount' => $pay,
                    'reference' => $ref,
                    'transaction_id' => $transactionId,
                    'notes' => $notes,
                ]);

                $allocations[] = ['invcode' => (string) $invoice->invcode, 'amount' => $pay];
                $remaining = round($remaining - $pay, 2);
            }

            $onAccount = 0.0;
            if ($remaining > 0.005) {
                Payment::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'invoice_id' => null, // on-account credit / προκαταβολή
                    'payment_intent_id' => $paymentIntentId,
                    'kind' => 'payment',
                    'payment_method_id' => $paymentMethodId,
                    'bank_account_id' => $bankAccountId,
                    'pay_date' => $date->toDateString(),
                    'amount' => $remaining,
                    'reference' => $ref,
                    'transaction_id' => $transactionId,
                    'notes' => trim(($notes ? $notes.' · ' : '').'Πίστωση / προκαταβολή (on-account)'),
                ]);
                $onAccount = $remaining;
            }

            return new PaymentAllocationResult($ref, $amount, $allocations, $onAccount);
        });
    }

    /**
     * The FIFO target set for a customer-level receipt: live (not cancelled /
     * AADE-cancelled), ISSUED (active — never a draft), non-credit-note invoices
     * (MON-9: a payment must never auto-allocate onto a ΠΙΣ), oldest first. The
     * SINGLE source shared by allocate() (the write) and absorbableTotal() (the
     * read-only preview), so a guard built on the latter can never target a
     * different set than the former settles.
     */
    private function openInvoicesQuery(Customer $customer): Builder
    {
        $q = InvoiceScope::live(Invoice::query())
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('local_status', 'active')
            ->orderBy('issued_at')
            ->orderBy('id');
        InvoiceScope::excludeCreditNotes($q);

        return $q;
    }

    /**
     * READ-ONLY preview of how much a customer-level receipt (allocate()) can settle
     * onto invoices before the remainder is parked on-account — i.e. Σ of the open
     * balances the FIFO sweep would absorb. Reuses openInvoicesQuery() and the SAME
     * live balanceData()->balance + 0.005 tolerance as allocate(), so a UI guard
     * built on it can never disagree with what the write actually does. Cost is one
     * balance read per open invoice (same as allocate()); callers should memoise.
     */
    public function absorbableTotal(Customer $customer): float
    {
        $total = 0.0;
        foreach ($this->openInvoicesQuery($customer)->with('paymentMethod')->get() as $invoice) {
            $balance = round((float) $invoice->balanceData()->balance, 2);
            if ($balance > 0.005) {
                $total += $balance;
            }
        }

        return round($total, 2);
    }

    /**
     * #1b — INVOICE-TARGETED allocation (portal «πλήρωσε ΑΥΤΟ το τιμολόγιο»): apply
     * the amount to ONE chosen invoice, capped at its own balance, and park any
     * remainder as on-account credit — so an over-payment funds the customer's
     * account instead of driving the invoice negative. The target must be THIS
     * customer's, live and issued (active), and not a credit note (MON-9). Mirrors
     * {@see allocate} but skips the FIFO sweep.
     */
    public function allocateToInvoice(
        Customer $customer,
        Invoice $invoice,
        float $amount,
        Carbon $date,
        ?int $paymentMethodId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $transactionId = null,
        ?int $bankAccountId = null,
        ?int $paymentIntentId = null,
    ): PaymentAllocationResult {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Το ποσό της είσπραξης πρέπει να είναι θετικό.');
        }

        $ref = $reference ?: 'ΕΙΣ-'.now()->format('YmdHis').'-'.substr(uniqid(), -4);

        return DB::transaction(function () use ($customer, $invoice, $amount, $date, $paymentMethodId, $ref, $notes, $transactionId, $bankAccountId, $paymentIntentId) {
            // Re-resolve the target under the customer/scope guard (never trust the
            // passed model's ownership): this customer's, live, issued, non-credit.
            // Drop the ambient CompanyScope (like PaymentIntentService::payableTarget,
            // which pre-checked the same row) and rely on the EXPLICIT company_id —
            // so settle() from a mismatched context (a future job) can't filter the
            // row out and roll back, stranding a captured payment.
            $target = InvoiceScope::live(Invoice::query())
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->where('local_status', 'active')
                ->whereKey($invoice->id);
            InvoiceScope::excludeCreditNotes($target);
            $target = $target->first();

            if ($target === null) {
                throw new InvalidArgumentException('Μη έγκυρο τιμολόγιο για πληρωμή (#'.$invoice->id.').');
            }

            $allocations = [];
            $remaining = $amount;

            $balance = round((float) $target->balanceData()->balance, 2);
            $toInvoice = round(min($balance, $remaining), 2);
            if ($toInvoice > 0.005) {
                Payment::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'invoice_id' => $target->id,
                    'payment_intent_id' => $paymentIntentId,
                    'kind' => 'payment',
                    'payment_method_id' => $paymentMethodId,
                    'bank_account_id' => $bankAccountId,
                    'pay_date' => $date->toDateString(),
                    'amount' => $toInvoice,
                    'reference' => $ref,
                    'transaction_id' => $transactionId,
                    'notes' => $notes,
                ]);
                $allocations[] = ['invcode' => (string) $target->invcode, 'amount' => $toInvoice];
                $remaining = round($remaining - $toInvoice, 2);
            }

            $onAccount = 0.0;
            if ($remaining > 0.005) {
                Payment::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'invoice_id' => null,
                    'payment_intent_id' => $paymentIntentId,
                    'kind' => 'payment',
                    'payment_method_id' => $paymentMethodId,
                    'bank_account_id' => $bankAccountId,
                    'pay_date' => $date->toDateString(),
                    'amount' => $remaining,
                    'reference' => $ref,
                    'transaction_id' => $transactionId,
                    'notes' => trim(($notes ? $notes.' · ' : '').'Πίστωση / προκαταβολή (on-account)'),
                ]);
                $onAccount = $remaining;
            }

            return new PaymentAllocationResult($ref, $amount, $allocations, $onAccount);
        });
    }

    /**
     * #2 — MANUAL allocation: the operator says exactly how much goes on each
     * invoice (vs the FIFO {@see allocate}). One Payment row per line, all
     * sharing a reference so the Καρτέλα groups them. Each invoice must belong
     * to the customer and be live + issued (active); amounts must be positive.
     *
     * @param  array<int, array{invoice_id: int, amount: float}>  $lines
     */
    public function allocateManual(
        Customer $customer,
        array $lines,
        Carbon $date,
        ?int $paymentMethodId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $transactionId = null,
        ?int $bankAccountId = null,
    ): PaymentAllocationResult {
        // Normalise + validate the lines (drop blanks/zeros).
        $clean = [];
        foreach ($lines as $line) {
            $invoiceId = (int) ($line['invoice_id'] ?? 0);
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($invoiceId <= 0 || $amount <= 0) {
                continue;
            }
            $clean[] = ['invoice_id' => $invoiceId, 'amount' => $amount];
        }
        if ($clean === []) {
            throw new InvalidArgumentException('Δεν δόθηκε καμία γραμμή κατανομής με θετικό ποσό.');
        }

        $ref = $reference ?: 'ΕΙΣ-'.now()->format('YmdHis').'-'.substr(uniqid(), -4);

        return DB::transaction(function () use ($customer, $clean, $date, $paymentMethodId, $ref, $notes, $transactionId, $bankAccountId) {
            $allocations = [];
            $total = 0.0;

            foreach ($clean as $line) {
                // Each target must be THIS customer's, live and issued.
                // MON-9: exclude credit notes (correlated AND standalone legacy) —
                // a payment can't be allocated to a ΠΙΣ.
                $target = InvoiceScope::live(Invoice::query())
                    ->where('company_id', $customer->company_id)
                    ->where('customer_id', $customer->id)
                    ->where('local_status', 'active')
                    ->whereKey($line['invoice_id']);
                InvoiceScope::excludeCreditNotes($target);
                $invoice = $target->first();

                if ($invoice === null) {
                    throw new InvalidArgumentException('Μη έγκυρο τιμολόγιο για κατανομή (#'.$line['invoice_id'].').');
                }

                Payment::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice->id,
                    'kind' => 'payment',
                    'payment_method_id' => $paymentMethodId,
                    'bank_account_id' => $bankAccountId,
                    'pay_date' => $date->toDateString(),
                    'amount' => $line['amount'],
                    'reference' => $ref,
                    'transaction_id' => $transactionId,
                    'notes' => $notes,
                ]);

                $allocations[] = ['invcode' => (string) $invoice->invcode, 'amount' => $line['amount']];
                $total = round($total + $line['amount'], 2);
            }

            return new PaymentAllocationResult($ref, $total, $allocations, 0.0);
        });
    }

    /**
     * #1 — apply a customer's existing ON-ACCOUNT credit (unallocated payments,
     * invoice_id null) onto one open invoice, by RE-POINTING those payment rows
     * (splitting the last if needed). Net-zero to the customer's total balance —
     * the money just moves from "credit" to "this invoice". Returns the amount
     * actually applied (capped by the invoice balance AND the available credit).
     */
    public function applyCredit(Customer $customer, Invoice $target, float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Το ποσό πρέπει να είναι θετικό.');
        }

        return DB::transaction(function () use ($customer, $target, $amount) {
            // Lock the WHOLE on-account pool FIRST (payments AND refunds) so
            // concurrent applies — and concurrent on-account refunds, which
            // shift the available-credit cap — serialise; only then read the
            // caps, so they reflect any prior committed apply/refund (otherwise
            // two writers read stale pre-lock figures and over-apply / misreport).
            // We still only RE-POINT 'payment' rows below; refund rows are locked
            // for the cap but never moved.
            $onAccount = Payment::query()
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->whereNull('invoice_id')
                ->orderBy('pay_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Caps read UNDER the lock: invoice balance from a fresh compute
            // (post any prior move) and available credit net of on-account refunds.
            $balance = round((float) app(InvoiceBalance::class)->for($target->fresh(['paymentMethod']))->balance, 2);
            if ($balance <= 0.005) {
                throw new InvalidArgumentException('Το τιμολόγιο δεν έχει ανοιχτό υπόλοιπο.');
            }
            $available = $this->availableCredit($customer);
            if ($available <= 0.005) {
                throw new InvalidArgumentException('Δεν υπάρχει διαθέσιμη πίστωση προς εφαρμογή.');
            }

            // Fresh reference so the applied credit reads as its OWN ledger event
            // («Είσπραξη ΕΦΑ-…») instead of folding into the original έμβασμα group.
            $ref = 'ΕΦΑ-'.now()->format('YmdHis').'-'.substr(uniqid(), -4);

            $remaining = round(min($amount, $balance, $available), 2);
            $applied = 0.0;

            foreach ($onAccount as $payment) {
                if ($remaining <= 0.005) {
                    break;
                }
                if ($payment->kind === 'refund') {
                    continue; // locked for the cap, never moved
                }
                $rowAmount = round((float) $payment->amount, 2);
                if ($rowAmount <= $remaining + 0.005) {
                    // Move the whole row onto the invoice (observer recomputes it).
                    $payment->update(['invoice_id' => $target->id, 'reference' => $ref]);
                    $applied = round($applied + $rowAmount, 2);
                    $remaining = round($remaining - $rowAmount, 2);
                } else {
                    // Split: shrink the on-account row, create the applied portion.
                    $payment->update(['amount' => round($rowAmount - $remaining, 2)]);
                    Payment::create([
                        'company_id' => $customer->company_id,
                        'customer_id' => $customer->id,
                        'invoice_id' => $target->id,
                        // Preserve the intent trail: the split-off portion is the
                        // SAME money as the on-account row it came from.
                        'payment_intent_id' => $payment->payment_intent_id,
                        'kind' => 'payment',
                        'payment_method_id' => $payment->payment_method_id,
                        'bank_account_id' => $payment->bank_account_id,
                        'pay_date' => $payment->pay_date,
                        'amount' => $remaining,
                        'reference' => $ref,
                        'transaction_id' => $payment->transaction_id,
                        'notes' => trim((string) ($payment->notes ?? '').' · εφαρμογή πίστωσης'),
                    ]);
                    $applied = round($applied + $remaining, 2);
                    $remaining = 0.0;
                }
            }

            // Return what was ACTUALLY moved (≤ the caps), not the pre-loop target.
            return $applied;
        });
    }

    /**
     * A customer's available on-account credit = Σ unallocated payments −
     * Σ unallocated refunds (invoice_id null). The pool {@see applyCredit} draws from.
     */
    public function availableCredit(Customer $customer): float
    {
        $row = Payment::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->whereNull('invoice_id')
            ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net_credit')
            ->first();

        return round((float) ($row->net_credit ?? 0), 2);
    }
}
