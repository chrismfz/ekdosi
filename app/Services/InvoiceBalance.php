<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * THE single source of truth for an invoice's money status. Nothing
 * else computes owed/paid/credited/balance/status independently.
 *
 *   owed    = gross_total − credited_total
 *   balance = owed − paid_total
 *
 * - credited_total = Σ gross of issued, non-cancelled credit notes
 *   (credited_invoice_id = this). A CANCELLED credit note doesn't count.
 * - paid_total = Σ amount of non-trashed payments allocated to this invoice.
 * - Cash-term invoices (payment_method.due_days = 0) are settled at issue
 *   → always `paid` (mirrors legacy GET_CUSTOMER_BALANCE, which excludes
 *   cash-term invoices from the receivable).
 *
 * Money compared with a 0.005 tolerance so 2dp rounding never flips a
 * status spuriously (same style as MyDataSubmitter's VAT matching).
 *
 * `for()` computes live from durable rows (for UI). `recompute()`
 * persists the denormalised cache columns and MUST run inside the
 * caller's transaction (like RecomputeInvoiceTotals).
 */
class InvoiceBalance
{
    private const EPS = 0.005;

    public function for(Invoice $invoice): InvoiceBalanceData
    {
        $gross = (float) ($invoice->gross_total ?? 0);
        $credited = $this->creditedTotal($invoice);
        $paid = $this->paidTotal($invoice);

        $owed = round($gross - $credited, 2);
        $balance = round($owed - $paid, 2);
        $status = $this->status($invoice, $gross, $credited, $paid, $owed, $balance);

        return new InvoiceBalanceData(
            gross: round($gross, 2),
            credited: round($credited, 2),
            paid: round($paid, 2),
            owed: $owed,
            balance: $balance,
            status: $status,
        );
    }

    /**
     * Recompute + persist the cache columns. Locks the invoice row so
     * concurrent payment writes serialise their cache updates. Caller
     * must already be inside a DB transaction.
     */
    public function recompute(Invoice $invoice): void
    {
        $invoice->newQuery()->whereKey($invoice->getKey())->lockForUpdate()->first();

        $data = $this->for($invoice);

        $invoice->forceFill([
            'paid_total'     => $data->paid,
            'credited_total' => $data->credited,
            'payment_status' => $data->status->value,
        ])->save();
    }

    private function creditedTotal(Invoice $invoice): float
    {
        if (! $invoice->exists) {
            return 0.0;
        }

        // Issued, non-cancelled credit notes reduce what's owed — same
        // "live invoice" filter used for receivables (null state = issued
        // but not yet filed still counts; works for off-mode tenants that
        // never reach VALID). A CANCELLED credit note does not.
        return (float) DB::table('invoices')
            ->where('credited_invoice_id', $invoice->getKey())
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->whereNull('mydata_state')
                ->orWhere('mydata_state', '!=', 'CANCELLED'))
            ->sum('gross_total');
    }

    private function paidTotal(Invoice $invoice): float
    {
        if (! $invoice->exists) {
            return 0.0;
        }

        return (float) DB::table('payments')
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('deleted_at')
            ->sum('amount');
    }

    private function status(
        Invoice $invoice,
        float $gross,
        float $credited,
        float $paid,
        float $owed,
        float $balance,
    ): PaymentStatus {
        // Cash-term invoices are settled the moment they're issued.
        if ($invoice->relationLoaded('paymentMethod')) {
            $dueDays = $invoice->paymentMethod?->due_days;
        } else {
            $dueDays = optional($invoice->paymentMethod()->first())->due_days;
        }
        if ((int) ($dueDays ?? 0) === 0 && $credited < $gross - self::EPS) {
            return PaymentStatus::Paid;
        }

        // Fully credited (return) wins over payment state.
        if ($credited >= $gross - self::EPS && $gross > self::EPS) {
            return PaymentStatus::Credited;
        }

        if ($paid > $owed + self::EPS) {
            return PaymentStatus::Overpaid;
        }

        if (abs($balance) <= self::EPS) {
            return PaymentStatus::Paid;
        }

        if ($paid > self::EPS) {
            return PaymentStatus::Partial;
        }

        return PaymentStatus::Unpaid;
    }
}
