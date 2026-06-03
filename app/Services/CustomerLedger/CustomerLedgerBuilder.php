<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use App\Support\InvoiceScope;
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
                'year' => $year,
                'invoice_type_id' => $invoiceTypeId,
                'paid_status' => $paidStatus,
            ],
        );
    }

    /**
     * Build ONLY the filter-independent sections (stats + aging +
     * yearly). Used by the Filament page to cache these across filter
     * changes - they don't depend on year/type/paid filters, so
     * recomputing them every time the operator clicks a filter is
     * wasted CPU.
     *
     * @return array{stats: array, aging: array, yearly: array}
     */
    public function buildStatsBlock(Customer $customer): array
    {
        $invoices = $this->loadInvoices($customer);
        $payments = $this->loadPayments($customer);

        return [
            'stats' => $this->computeStats($invoices, $payments),
            'aging' => $this->computeAging($invoices, $payments),
            'yearly' => $this->computeYearly($invoices, $payments),
        ];
    }

    /**
     * Build ONLY the chronological ledger array. Re-run on every
     * filter change. Loads invoices + payments fresh each time so
     * stat-block staleness across long-lived component sessions
     * (operator leaves the page open, a new invoice gets issued in
     * another tab) doesn't compound: the ledger is always current,
     * the cached stats block is only as fresh as the page mount.
     *
     * @param  array{year?: ?int, invoice_type_id?: ?int, paid_status?: ?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function buildLedgerOnly(Customer $customer, array $filters = []): array
    {
        $invoices = $this->loadInvoices($customer);
        $payments = $this->loadPayments($customer);

        return $this->computeLedger(
            $invoices,
            $payments,
            $filters['year'] ?? null,
            $filters['invoice_type_id'] ?? null,
            $filters['paid_status'] ?? null,
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
            // Exclude CANCELLED invoices (locally OR at myDATA, incl.
            // cancelled credit notes) from the ledger money math,
            // consistent with InvoiceBalance + DashboardMetrics.
            ->when(true, fn ($q) => InvoiceScope::live($q, 'invoices.'))
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
                'invoices.credited_invoice_id',
                'payment_methods.due_days',
                'invoice_types.code as invoice_type_code',
                'invoice_types.is_credit',
            )
            ->get();
    }

    /**
     * A credit note (credit-type invoice or one issued against an
     * original) credits the customer's account — it REDUCES what they
     * owe rather than adding a receivable. Treated like a payment in the
     * FIFO balance/aging math and as a credit (not a debit) in the
     * timeline. Standalone credit-type invoices count too via is_credit.
     */
    private function isCreditNote(object $inv): bool
    {
        return $inv->credited_invoice_id !== null || (bool) ($inv->is_credit ?? false);
    }

    /**
     * Set of invoice ids that carry at least one (non-trashed) payment row, as
     * an id => true map for O(1) lookup. loadPayments() already excludes trashed.
     *
     * @param  Collection<int, object>  $payments
     * @return array<int, true>
     */
    private function paidInvoiceIds(Collection $payments): array
    {
        return $payments
            ->pluck('invoice_id')
            ->filter()
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Does this invoice count toward the receivables balance? Credit-term
     * always; a cash-term invoice ONLY once it carries a recorded payment (the
     * money-trail exception — then its debit AND its payment both enter the
     * math, netting to zero). Mirrors InvoiceBalance + the receivables predicate
     * in DashboardMetrics / Customer, so all surfaces agree.
     *
     * @param  array<int, true>  $paidIds
     */
    private function isTracked(object $inv, array $paidIds): bool
    {
        return ((int) ($inv->due_days ?? 0)) > 0 || isset($paidIds[(int) $inv->id]);
    }

    /**
     * @return Collection<int, object>
     */
    private function loadPayments(Customer $customer): Collection
    {
        return DB::table('payments')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            // DB::table bypasses the SoftDeletes global scope — exclude
            // trashed payments explicitly (a deleted payment must not
            // keep reducing the balance).
            ->whereNull('deleted_at')
            ->orderBy('pay_date', 'asc')
            ->orderBy('id', 'asc')
            // reference/invoice_id/notes are needed by the Φ3 grouping in
            // computeLedger() (collapse one «έμβασμα/είσπραξη» into one row).
            // The money math (stats/aging/yearly) ignores them.
            ->select('id', 'pay_date', 'amount', 'kind', 'reference', 'invoice_id', 'notes')
            ->get();
    }

    /**
     * Operator-facing label for a ledger event row — the SINGLE source shared by
     * the Καρτέλα table column, the CSV export and the PDF statement (was three
     * identical match() blocks). `invoice` carries the invoice-type code.
     */
    public static function eventTypeLabel(string $type, ?string $invoiceTypeCode = null): string
    {
        return match ($type) {
            'invoice' => $invoiceTypeCode ?? 'Τιμολόγιο',
            'refund' => 'Επιστροφή',
            default => 'Πληρωμή',
        };
    }

    /**
     * Signed contribution of a payment row to the paid total: a refund
     * (money OUT, back to the customer) counts NEGATIVE — it un-pays, so the
     * balance rises again. Mirrors Payment::NET_AMOUNT_SQL on the SQL side.
     */
    private function signedAmount(object $p): float
    {
        return (($p->kind ?? 'payment') === 'refund' ? -1.0 : 1.0) * (float) $p->amount;
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

        // Balance: credit-term invoices only minus all payments. Credit
        // notes reduce the balance like a payment (creditReductions).
        $creditTermGross = 0.0;
        $creditReductions = 0.0;
        $paidIds = $this->paidInvoiceIds($payments);

        $lastActivity = null;

        foreach ($invoices as $inv) {
            $issuedAt = Carbon::parse($inv->issued_at);
            $isCreditTerm = $this->isTracked($inv, $paidIds);
            $isCreditNote = $this->isCreditNote($inv);
            $sign = $isCreditNote ? -1 : 1;

            if ($issuedAt->year === $currentYear) {
                $ytdNet += $sign * (float) $inv->net_total;
                $ytdGross += $sign * (float) $inv->gross_total;
            }

            if ($isCreditNote) {
                $creditReductions += (float) $inv->gross_total;
            } elseif ($isCreditTerm) {
                $creditTermGross += (float) $inv->gross_total;
            }

            if ($lastActivity === null || $issuedAt->gt($lastActivity)) {
                $lastActivity = $issuedAt;
            }
        }

        $totalPaidLifetime = 0.0;
        foreach ($payments as $p) {
            $payDate = $p->pay_date ? Carbon::parse($p->pay_date) : null;
            // Refunds count negative — they reduce the paid total.
            $amount = $this->signedAmount($p);
            $totalPaidLifetime += $amount;
            if ($payDate && $payDate->year === $currentYear) {
                $ytdPaid += $amount;
            }
            if ($payDate && ($lastActivity === null || $payDate->gt($lastActivity))) {
                $lastActivity = $payDate;
            }
        }

        // Credit notes settle receivables just like payments do.
        $effectivePaid = $totalPaidLifetime + $creditReductions;
        $balance = round($creditTermGross - $effectivePaid, 2);

        // Oldest unpaid is meaningful only if balance > 0. If settled,
        // return null (UI shows "—"). Previous implementation picked
        // MIN(issued_at) of ALL credit-term invoices regardless of
        // paid state — surfacing decade-old already-paid invoices as
        // "oldest unpaid" whenever a newer credit-term invoice was
        // genuinely unpaid. Fix: FIFO walk applying total payments
        // against credit-term invoices in issue order; the FIRST
        // invoice with leftover unpaid is the genuine oldest-unpaid.
        $oldestUnpaidDays = null;
        if ($balance > 0) {
            $remainingPaid = $effectivePaid;
            // Defensive sort: the FIFO correctness depends on iterating
            // invoices oldest-first. loadInvoices() currently does
            // orderBy('issued_at','asc') but that contract is not
            // asserted at this site — a future loadInvoices change to
            // a different ORDER BY (id desc for index-plan reasons,
            // for example) would silently flip the FIFO to LIFO and
            // resurrect the original "ghost-debt" bug fix #4 was
            // meant to eliminate.
            $orderedInvoices = $invoices
                ->sortBy(fn ($inv) => Carbon::parse($inv->issued_at)->timestamp)
                ->values();
            foreach ($orderedInvoices as $inv) {
                $isCreditTerm = $this->isTracked($inv, $paidIds);
                if (! $isCreditTerm || $this->isCreditNote($inv)) {
                    continue;
                }
                $gross = (float) $inv->gross_total;
                if ($remainingPaid >= $gross) {
                    $remainingPaid -= $gross;

                    continue;
                }
                $oldestUnpaidDays = (int) Carbon::parse($inv->issued_at)->diffInDays(now());
                break;
            }
        }

        return [
            'ytd_net' => round($ytdNet, 2),
            'ytd_gross' => round($ytdGross, 2),
            'ytd_paid' => round($ytdPaid, 2),
            'balance' => $balance,
            'oldest_unpaid_days' => $oldestUnpaidDays,
            'last_activity_at' => $lastActivity?->toIso8601String(),
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
        // Credit notes settle receivables FIFO just like payments; refunds
        // count negative (signedAmount) — they un-settle.
        $totalPaid = (float) $payments->sum(fn ($p) => $this->signedAmount($p))
            + (float) $invoices->filter(fn ($inv) => $this->isCreditNote($inv))->sum('gross_total');
        $bucket = [
            'bucket_0_30' => 0.0,
            'bucket_31_60' => 0.0,
            'bucket_61_90' => 0.0,
            'bucket_90_plus' => 0.0,
        ];
        $paidIds = $this->paidInvoiceIds($payments);

        foreach ($invoices as $inv) {
            $isCreditTerm = $this->isTracked($inv, $paidIds);
            if (! $isCreditTerm || $this->isCreditNote($inv)) {
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
            // Credit notes carry negative net/gross (they reduce sales +
            // the year-end running balance).
            $sign = $this->isCreditNote($inv) ? -1 : 1;
            $byYear[$year] ??= ['year' => $year, 'invoice_count' => 0, 'net' => 0.0, 'gross' => 0.0, 'paid' => 0.0];
            $byYear[$year]['invoice_count']++;
            $byYear[$year]['net'] += $sign * (float) $inv->net_total;
            $byYear[$year]['gross'] += $sign * (float) $inv->gross_total;
        }

        foreach ($payments as $p) {
            if (! $p->pay_date) {
                continue;
            }
            $year = (int) Carbon::parse($p->pay_date)->year;
            $byYear[$year] ??= ['year' => $year, 'invoice_count' => 0, 'net' => 0.0, 'gross' => 0.0, 'paid' => 0.0];
            // Refunds reduce that year's paid (signedAmount).
            $byYear[$year]['paid'] += $this->signedAmount($p);
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
        $paidIds = $this->paidInvoiceIds($payments);
        foreach ($invoices as $inv) {
            // A credit note lands in the CREDIT column (reduces running
            // balance); a normal invoice is a debit. is_credit_term is
            // forced false on credit notes so the debit branch is skipped.
            $isCreditNote = $this->isCreditNote($inv);
            $gross = (float) $inv->gross_total;
            $events[] = [
                'date_sort' => Carbon::parse($inv->issued_at)->timestamp,
                'date' => Carbon::parse($inv->issued_at)->toDateString(),
                'type' => 'invoice',
                'invoice_id' => (int) $inv->id,
                'payment_id' => null,
                'code' => $inv->invcode,
                'reference' => $inv->invcode ?? ('#'.$inv->id),
                'invoice_type_id' => (int) $inv->invoice_type_id,
                'invoice_type_code' => $inv->invoice_type_code,
                'debit' => $isCreditNote ? 0.0 : $gross,
                'credit' => $isCreditNote ? $gross : 0.0,
                'mydata_state' => $inv->mydata_state,
                'mydata_mark' => $inv->mydata_mark,
                'is_credit_term' => ! $isCreditNote && $this->isTracked($inv, $paidIds),
                'is_receipt_group' => false,
                'allocations' => null,
            ];
        }
        // Φ3 — payments that share a NON-NULL `reference` (a PaymentAllocator
        // «έμβασμα/είσπραξη»: N invoice-allocations + maybe one on-account
        // remainder) collapse into ONE ledger event with the SUM of their
        // amounts and a drill-down `allocations` list. Payments with a NULL
        // reference (legacy, manual cockpit single payments, the old
        // on-account action) stay as individual rows exactly as before.
        //
        // Grouping the N credits of total X into one credit of X cannot change
        // the running balance — each payment is a pure `-= credit` and addition
        // is associative, so the cumulative figure is byte-identical.
        $invcodeById = $invoices->mapWithKeys(
            fn ($inv) => [(int) $inv->id => $inv->invcode],
        );

        $referenced = [];   // reference => list<payment row>
        foreach ($payments as $p) {
            if (! $p->pay_date) {
                continue;
            }

            // A refund (money OUT, back to the customer) is the reverse of a
            // payment: a DEBIT that raises the balance again. Always an
            // individual row — never folded into an έμβασμα group.
            if (($p->kind ?? 'payment') === 'refund') {
                $events[] = [
                    'date_sort' => Carbon::parse($p->pay_date)->timestamp,
                    'date' => Carbon::parse($p->pay_date)->toDateString(),
                    'type' => 'refund',
                    'invoice_id' => $p->invoice_id !== null ? (int) $p->invoice_id : null,
                    'payment_id' => (int) $p->id,
                    'code' => null,
                    'reference' => 'Επιστροφή χρημάτων #'.$p->id,
                    'invoice_type_id' => null,
                    'invoice_type_code' => null,
                    'debit' => (float) $p->amount,
                    'credit' => 0.0,
                    'mydata_state' => null,
                    'mydata_mark' => null,
                    'is_credit_term' => false,
                    'is_receipt_group' => false,
                    'allocations' => null,
                ];

                continue;
            }

            if ($p->reference === null || $p->reference === '') {
                $events[] = [
                    'date_sort' => Carbon::parse($p->pay_date)->timestamp,
                    'date' => Carbon::parse($p->pay_date)->toDateString(),
                    'type' => 'payment',
                    'invoice_id' => null,
                    'payment_id' => (int) $p->id,
                    'code' => null,
                    'reference' => 'Πληρωμή #'.$p->id,
                    'invoice_type_id' => null,
                    'invoice_type_code' => null,
                    'debit' => 0.0,
                    'credit' => (float) $p->amount,
                    'mydata_state' => null,
                    'mydata_mark' => null,
                    'is_credit_term' => false,
                    'is_receipt_group' => false,
                    'allocations' => null,
                ];

                continue;
            }

            $referenced[$p->reference] ??= [];
            $referenced[$p->reference][] = $p;
        }

        foreach ($referenced as $reference => $group) {
            $earliest = null;
            $sum = 0.0;
            $allocations = [];
            $isReceipt = false; // any allocation against an invoice → «Έμβασμα»

            foreach ($group as $p) {
                $ts = Carbon::parse($p->pay_date)->timestamp;
                if ($earliest === null || $ts < $earliest) {
                    $earliest = $ts;
                }
                $amount = (float) $p->amount;
                $sum += $amount;

                $invoiceId = $p->invoice_id !== null ? (int) $p->invoice_id : null;
                if ($invoiceId !== null) {
                    $isReceipt = true;
                    $invcode = $invcodeById[$invoiceId] ?? ('#'.$invoiceId);
                    $allocations[] = [
                        'label' => (string) $invcode,
                        'amount' => round($amount, 2),
                        'invoice_id' => $invoiceId,
                        'invcode' => $invcode !== null ? (string) $invcode : null,
                    ];
                } else {
                    $allocations[] = [
                        'label' => 'Πίστωση / προκαταβολή',
                        'amount' => round($amount, 2),
                        'invoice_id' => null,
                        'invcode' => null,
                    ];
                }
            }

            $label = $isReceipt
                ? 'Έμβασμα '.$reference
                : 'Είσπραξη '.$reference;

            $events[] = [
                'date_sort' => $earliest,
                'date' => Carbon::createFromTimestamp($earliest)->toDateString(),
                'type' => 'payment',
                'invoice_id' => null,
                'payment_id' => null,
                'code' => null,
                'reference' => $label,
                'invoice_type_id' => null,
                'invoice_type_code' => null,
                'debit' => 0.0,
                'credit' => round($sum, 2),
                'mydata_state' => null,
                'mydata_mark' => null,
                'is_credit_term' => false,
                'is_receipt_group' => true,
                'allocations' => $allocations,
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
            // A refund is money returned to the customer → raises the balance.
            if ($e['type'] === 'refund') {
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
