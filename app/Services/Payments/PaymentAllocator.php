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
}
