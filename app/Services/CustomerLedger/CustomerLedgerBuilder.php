<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Καρτέλα Πελάτη: builds the full customer ledger result from a
 * customer + optional filters.
 *
 * What it produces (one DB-light pass over invoices + payments):
 *   1. Quick stats - YTD net/gross/paid, current balance, oldest
 *      unpaid days, last activity timestamp
 *   2. Aging buckets - 0-30 / 31-60 / 61-90 / 90+ days for unpaid
 *      credit-term invoices
 *   3. Yearly breakdown - per-year invoice count + totals + paid
 *      + year-end running balance
 *   4. Chronological ledger - merged invoice + payment rows in date
 *      DESC order with running balance, filtered per request
 *
 * Balance semantics mirror the legacy GET_CUSTOMER_BALANCE stored
 * procedure (schema.sql:652):
 *   balance = SUM(invoice.gross_total WHERE due_days > 0)
 *           - SUM(payment.amount)
 * Cash-term invoices (payment_method.due_days = 0) do NOT count
 * toward balance. Credit-term invoices DO. This is a load-bearing
 * Greek-accounting convention - cash sales are settled at point
 * of sale and don't create receivables.
 *
 * The "running balance" in the ledger view is a cumulative SUM of
 * debits (all invoices, sign-respecting) minus credits (payments),
 * walking the timeline forward. We compute it walking OLDEST first
 * then return the array reversed so the view shows newest-first
 * without re-computing.
 *
 * Tenant scoping: pulls $customer->company_id everywhere. No global
 * tenant scope on Customer/Invoice/Payment per CLAUDE.md.
 */
class CustomerLedgerBuilder
{
    /**
     * @param  array{
     *     year?: ?int,
     *     invoice_type_id?: ?int,
     *     paid_status?: ?string,
     * }  $filters
     */
    public function build(Customer $customer, array $filters = []): CustomerLedgerResult
    {
        $year = $filters['year'] ?? null;
        $invoiceTypeId = $filters['invoice_type_id'] ?? null;
        $paidStatus = $filters['paid_status'] ?? null;

        // Load every invoice + payment for this customer in one go.
        // Even for a 20-year-old customer this is bounded by their
        // own activity (typically <500 rows lifetime per customer).
        $invoices = $this->loadInvoices($customer);
        $payments = $this->loadPayments($customer);

        $stats = $this->computeStats($invoices, $payments);
        $aging = $this->computeAging($invoices, $payments);
        $yearly = $this->computeYearly($invoices, $payments);
        $ledger = $this->computeLedger($invoices, $payments, $year, $invoiceTypeId, $paidStatus);

        return new CustomerLedgerResult(
            stats: $stats,
            aging: $aging,
            yearly: $yearly,
            ledger: $ledger,
            appliedFilters: [
                'year'            => $year,
                'invoice_type_id' => $invoiceTypeId,
                'paid_status'     => $paidStatus,
            ],
        );
    }

    /**
     * Invoice rows with their payment_method.due_days resolved (so we
     * can apply the credit-term-only balance rule). Returns rows as
     * arrays for predictable shape - no Eloquent attribute mutators
     * leaking into the math.
     *
     * @return Collection<int, object>
     */
    private function loadInvoices(Customer $customer): Collection
    {
        return DB::table('invoices')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'invoices.payment_method_id')
            ->leftJoin('invoice_types', 'invoice_types.id', '=', 'invoices.invoice_type_id')
            ->where('invoices.company_id', $customer->company_id)
            ->where('invoices.customer_id', $customer->id)
            ->whereNull('invoices.deleted_at')
            ->orderBy('invoices.issued_at', 'asc')
            ->select(
                'invoices.id',
                'invoices.invcode',
                'invoices.code',
                'invoices.invoice_type_id',
                'invoices.issued_at',
                'invoices.net_total',
                'invoices.gross_total',
                'invoices.mydata_state',
                'invoices.mydata_mark',
                'payment_methods.due_days',
                'invoice_types.code as invoice_type_code',
            )
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function loadPayments(Customer $customer): Collection
    {
        return DB::table('payments')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->orderBy('pay_date', 'asc')
            ->select('id', 'pay_date', 'amount')
            ->get();
    }

    /**
     * @param  Collection<int, object>  $invoices
     * @param  Collection<int, object>  $payments
     */
    private function computeStats(Collection $invoices, Collection $payments): array
    {
        $currentYear = now()->year;
        $ytdNet = 0.0;
        $ytdGross = 0.0;
        $ytdPaid = 0.0;

        // Balance: credit-term invoices only minus all payments.
        $creditTermGross = 0.0;

        $lastActivity = null;
        $oldestUnpaidIssue = null;

        foreach ($invoices as $inv) {
            $issuedAt = Carbon::parse($inv->issued_at);
            $isCreditTerm = ((int) ($inv->due_days ?? 0)) > 0;

            if ($issuedAt->year === $currentYear) {
                $ytdNet += (float) $inv->net_total;
                $ytdGross += (float) $inv->gross_total;
            }

            if ($isCreditTerm) {
                $creditTermGross += (float) $inv->gross_total;
                if ($oldestUnpaidIssue === null || $issuedAt->lt($oldestUnpaidIssue)) {
                    $oldestUnpaidIssue = $issuedAt;
                }
            }

            if ($lastActivity === null || $issuedAt->gt($lastActivity)) {
                $lastActivity = $issuedAt;
            }
        }

        $totalPaidLifetime = 0.0;
        foreach ($payments as $p) {
            $payDate = $p->pay_date ? Carbon::parse($p->pay_date) : null;
            $amount = (float) $p->amount;
            $totalPaidLifetime += $amount;
            if ($payDate && $payDate->year === $currentYear) {
                $ytdPaid += $amount;
            }
            if ($payDate && ($lastActivity === null || $payDate->gt($lastActivity))) {
                $lastActivity = $payDate;
            }
        }

        $balance = round($creditTermGross - $totalPaidLifetime, 2);

        // Oldest unpaid is meaningful only if balance > 0. If fully
        // settled, return null (UI shows "—").
        $oldestUnpaidDays = ($balance > 0 && $oldestUnpaidIssue !== null)
            ? (int) $oldestUnpaidIssue->diffInDays(now())
            : null;

        return [
            'ytd_net'                 => round($ytdNet, 2),
            'ytd_gross'               => round($ytdGross, 2),
            'ytd_paid'                => round($ytdPaid, 2),
            'balance'                 => $balance,
            'oldest_unpaid_days'      => $oldestUnpaidDays,
            'last_activity_at'        => $lastActivity?->toIso8601String(),
            'total_invoices_lifetime' => $invoices->count(),
        ];
    }

    /**
     * Aging buckets: how much of the OUTSTANDING balance falls in each
     * age window. Walks credit-term invoices, applies payments
     * FIFO-oldest-first to "settle" them, then buckets the remaining
     * unpaid amount by invoice age.
     *
     * The FIFO settlement is the standard Greek/EU accounting
     * convention: payments settle the oldest open invoice first
     * (legal default unless the customer specifies otherwise on the
     * payment reference, which we don't track yet).
     */
    private function computeAging(Collection $invoices, Collection $payments): array
    {
        $now = now();
        $totalPaid = (float) $payments->sum('amount');
        $bucket = [
            'bucket_0_30'    => 0.0,
            'bucket_31_60'   => 0.0,
            'bucket_61_90'   => 0.0,
            'bucket_90_plus' => 0.0,
        ];

        foreach ($invoices as $inv) {
            $isCreditTerm = ((int) ($inv->due_days ?? 0)) > 0;
            if (! $isCreditTerm) {
                continue;
            }

            $gross = (float) $inv->gross_total;
            if ($totalPaid >= $gross) {
                $totalPaid -= $gross;
                continue;
            }
            $unpaid = $gross - $totalPaid;
            $totalPaid = 0;

            $ageDays = (int) Carbon::parse($inv->issued_at)->diffInDays($now);
            if ($ageDays <= 30) {
                $bucket['bucket_0_30'] += $unpaid;
            } elseif ($ageDays <= 60) {
                $bucket['bucket_31_60'] += $unpaid;
            } elseif ($ageDays <= 90) {
                $bucket['bucket_61_90'] += $unpaid;
            } else {
                $bucket['bucket_90_plus'] += $unpaid;
            }
        }

        return array_map(fn ($v) => round($v, 2), $bucket);
    }

    /**
     * Per-year breakdown. Iterates oldest-first to compute year-end
     * running balance correctly (cumulative across years).
     */
    private function computeYearly(Collection $invoices, Collection $payments): array
    {
        $byYear = [];

        foreach ($invoices as $inv) {
            $year = (int) Carbon::parse($inv->issued_at)->year;
            $byYear[$year] ??= ['year' => $year, 'invoice_count' => 0, 'net' => 0.0, 'gross' => 0.0, 'paid' => 0.0];
            $byYear[$year]['invoice_count']++;
            $byYear[$year]['net'] += (float) $inv->net_total;
            $byYear[$year]['gross'] += (float) $inv->gross_total;
        }

        foreach ($payments as $p) {
            if (! $p->pay_date) {
                continue;
            }
            $year = (int) Carbon::parse($p->pay_date)->year;
            $byYear[$year] ??= ['year' => $year, 'invoice_count' => 0, 'net' => 0.0, 'gross' => 0.0, 'paid' => 0.0];
            $byYear[$year]['paid'] += (float) $p->amount;
        }

        ksort($byYear);
        $running = 0.0;
        $out = [];
        foreach ($byYear as $row) {
            $running += $row['gross'] - $row['paid'];
            $row['year_end_balance'] = round($running, 2);
            $row['net'] = round($row['net'], 2);
            $row['gross'] = round($row['gross'], 2);
            $row['paid'] = round($row['paid'], 2);
            $out[] = $row;
        }

        // Newest year first for display.
        return array_reverse($out);
    }

    /**
     * Chronological ledger: invoices + payments merged, oldest-first
     * for running-balance computation, then reversed for display.
     * Filters applied AFTER the running balance is computed so the
     * cumulative figure reflects the full history regardless of the
     * filter window (operator wouldn't expect a year filter to reset
     * the balance to zero).
     */
    private function computeLedger(
        Collection $invoices,
        Collection $payments,
        ?int $year,
        ?int $invoiceTypeId,
        ?string $paidStatus,
    ): array {
        $events = [];
        foreach ($invoices as $inv) {
            $events[] = [
                'date_sort' => Carbon::parse($inv->issued_at)->timestamp,
                'date'      => Carbon::parse($inv->issued_at)->toDateString(),
                'type'      => 'invoice',
                'invoice_id'=> (int) $inv->id,
                'payment_id'=> null,
                'code'      => $inv->invcode,
                'reference' => $inv->invcode ?? ('#'.$inv->id),
                'invoice_type_id'   => (int) $inv->invoice_type_id,
                'invoice_type_code' => $inv->invoice_type_code,
                'debit'     => (float) $inv->gross_total,
                'credit'    => 0.0,
                'mydata_state' => $inv->mydata_state,
                'mydata_mark'  => $inv->mydata_mark,
                'is_credit_term' => ((int) ($inv->due_days ?? 0)) > 0,
            ];
        }
        foreach ($payments as $p) {
            if (! $p->pay_date) {
                continue;
            }
            $events[] = [
                'date_sort' => Carbon::parse($p->pay_date)->timestamp,
                'date'      => Carbon::parse($p->pay_date)->toDateString(),
                'type'      => 'payment',
                'invoice_id'=> null,
                'payment_id'=> (int) $p->id,
                'code'      => null,
                'reference' => 'Πληρωμή #'.$p->id,
                'invoice_type_id'   => null,
                'invoice_type_code' => null,
                'debit'     => 0.0,
                'credit'    => (float) $p->amount,
                'mydata_state' => null,
                'mydata_mark'  => null,
                'is_credit_term' => false,
            ];
        }

        // Walk oldest-first to compute running balance.
        usort($events, fn ($a, $b) => $a['date_sort'] <=> $b['date_sort']);
        $running = 0.0;
        foreach ($events as $i => $e) {
            // Only credit-term invoices change the receivables balance;
            // cash-term invoices are settled-at-issue and don't add.
            // Payments always reduce the balance regardless of method
            // (legacy doesn't track which invoice a payment settles).
            if ($e['type'] === 'invoice' && $e['is_credit_term']) {
                $running += $e['debit'];
            }
            $running -= $e['credit'];
            $events[$i]['running_balance'] = round($running, 2);
        }

        // Apply filters AFTER balance computation.
        if ($year !== null) {
            $events = array_values(array_filter($events, fn ($e) => (int) substr($e['date'], 0, 4) === $year));
        }
        if ($invoiceTypeId !== null) {
            $events = array_values(array_filter($events, fn ($e) => $e['invoice_type_id'] === $invoiceTypeId));
        }
        // paid_status only meaningful on invoices: filter accordingly.
        // 'paid' = invoice that has been fully settled by FIFO payments.
        // We approximate by treating cash-term invoices as always paid,
        // and credit-term as unpaid (we don't track per-invoice
        // settlement, only customer-level). This is rough but matches
        // operator expectations until a per-invoice settlement table
        // exists.
        if ($paidStatus === 'paid') {
            $events = array_values(array_filter(
                $events,
                fn ($e) => $e['type'] === 'payment' || ! $e['is_credit_term'],
            ));
        } elseif ($paidStatus === 'unpaid') {
            $events = array_values(array_filter(
                $events,
                fn ($e) => $e['type'] === 'invoice' && $e['is_credit_term'],
            ));
        }

        // Newest first for display.
        usort($events, fn ($a, $b) => $b['date_sort'] <=> $a['date_sort']);

        // Strip the internal date_sort + is_credit_term cols from the
        // returned shape - view doesn't need them.
        foreach ($events as &$e) {
            unset($e['date_sort'], $e['is_credit_term']);
            $e['net'] = $e['debit'];   // for backward-compat / view convenience
        }
        unset($e);

        return $events;
    }
}
