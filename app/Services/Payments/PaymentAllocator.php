<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\InvoiceScope;
use Carbon\Carbon;
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
    ): PaymentAllocationResult {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Το ποσό της είσπραξης πρέπει να είναι θετικό.');
        }

        $ref = $reference ?: 'ΕΙΣ-'.now()->format('YmdHis').'-'.substr(uniqid(), -4);

        return DB::transaction(function () use ($customer, $amount, $date, $paymentMethodId, $ref, $notes, $transactionId, $bankAccountId) {
            $remaining = $amount;
            $allocations = [];

            // Live (not cancelled / not AADE-cancelled), ISSUED (active — never a
            // draft: a receipt must not land on a not-yet-issued document), and
            // non-credit-note invoices, oldest first. Cash-term & already-paid
            // invoices have balance 0 → skipped below. A draft's amount flows to
            // the on-account remainder instead.
            $open = InvoiceScope::live(Invoice::query())
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->where('local_status', 'active')
                ->whereNull('credited_invoice_id')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->get();

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
                $invoice = InvoiceScope::live(Invoice::query())
                    ->where('company_id', $customer->company_id)
                    ->where('customer_id', $customer->id)
                    ->where('local_status', 'active')
                    ->whereNull('credited_invoice_id')
                    ->whereKey($line['invoice_id'])
                    ->first();

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
            $balance = round((float) $target->balanceData()->balance, 2);
            if ($balance <= 0.005) {
                throw new InvalidArgumentException('Το τιμολόγιο δεν έχει ανοιχτό υπόλοιπο.');
            }

            $available = $this->availableCredit($customer);
            $toApply = round(min($amount, $balance, $available), 2);
            if ($toApply <= 0.005) {
                throw new InvalidArgumentException('Δεν υπάρχει διαθέσιμη πίστωση προς εφαρμογή.');
            }

            $remaining = $toApply;
            $onAccount = Payment::query()
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->whereNull('invoice_id')
                ->where('kind', 'payment')
                ->orderBy('pay_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($onAccount as $payment) {
                if ($remaining <= 0.005) {
                    break;
                }
                $rowAmount = round((float) $payment->amount, 2);
                if ($rowAmount <= $remaining + 0.005) {
                    // Move the whole row onto the invoice (observer recomputes it).
                    $payment->update(['invoice_id' => $target->id]);
                    $remaining = round($remaining - $rowAmount, 2);
                } else {
                    // Split: shrink the on-account row, create the applied portion.
                    $payment->update(['amount' => round($rowAmount - $remaining, 2)]);
                    Payment::create([
                        'company_id' => $customer->company_id,
                        'customer_id' => $customer->id,
                        'invoice_id' => $target->id,
                        'kind' => 'payment',
                        'payment_method_id' => $payment->payment_method_id,
                        'bank_account_id' => $payment->bank_account_id,
                        'pay_date' => $payment->pay_date,
                        'amount' => $remaining,
                        'reference' => $payment->reference,
                        'transaction_id' => $payment->transaction_id,
                        'notes' => trim((string) ($payment->notes ?? '').' · εφαρμογή πίστωσης'),
                    ]);
                    $remaining = 0.0;
                }
            }

            return $toApply;
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
