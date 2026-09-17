<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use App\Support\InvoiceScope;
use Carbon\CarbonInterface;
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
 *   balance = SUM(invoice.payable_total WHERE due_days > 0)
 *           - SUM(payment.amount)
 * where payable_total is the COLLECTIBLE (net+VAT + fees − withholding, AADE
 * [208]) — withholding/fees reduce what the customer actually pays. Turnover
 * stats (ytd_gross, the yearly gross/net columns) stay on gross_total.
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
     * Lean variant for the aged-receivables report: stats + aging only (skips the
     * yearly breakdown the report never reads). Same FIFO ageing as the Καρτέλα.
     *
     * @return array{stats: array, aging: array}
     */
    public function buildAgingBlock(Customer $customer): array
    {
        $invoices = $this->loadInvoices($customer);
        $payments = $this->loadPayments($customer);

        return [
            'stats' => $this->computeStats($invoices, $payments),
            'aging' => $this->computeAging($invoices, $payments),
        ];
    }

    /**
     * Period trial-balance figures for ONE customer — the row behind the
     * «Ισοζύγιο Πελατών» report (#4):
     *
     *   opening = tracked balance carried in at $start (all activity before it)
     *   debit   = credit-term charges (payable) issued within [$start, $end]
     *   credit  = payments + credit notes within [$start, $end]
     *   closing = opening + debit − credit
     *
     * Uses the SAME primitives as computeStats (isTracked credit-term gate, credit
     * notes + payments as credits, payable as the collectible), so `closing` for an
     * all-embracing window equals the Καρτέλα stats balance / the receivables
     * figure — the row reconciles with every other money surface by construction.
     * A payment with a NULL pay_date (legacy ETL raw insert) is folded into the
     * carry-over (unknown date = pre-period) so the lifetime reconciliation holds.
     * Activity dated AFTER $end is excluded (not yet on the books at $end).
     *
     * @return array{opening: float, debit: float, credit: float, closing: float}
     */
    public function periodBalances(Customer $customer, CarbonInterface $start, CarbonInterface $end): array
    {
        $invoices = $this->loadInvoices($customer);
        $payments = $this->loadPayments($customer);
        $paidIds = $this->paidInvoiceIds($payments);

        $openingCharges = 0.0;   // credit-term charges before $start
        $openingCredits = 0.0;   // credit notes + payments before $start
        $periodDebit = 0.0;      // credit-term charges within [$start, $end]
        $periodCredit = 0.0;     // credit notes + payments within [$start, $end]

        foreach ($invoices as $inv) {
            $issuedAt = Carbon::parse($inv->issued_at);
            if ($issuedAt->gt($end)) {
                continue; // future relative to the report end
            }
            $before = $issuedAt->lt($start);

            if ($this->isCreditNote($inv)) {
                $amount = $this->payable($inv);
                $before ? $openingCredits += $amount : $periodCredit += $amount;
            } elseif ($this->isTracked($inv, $paidIds)) {
                $amount = $this->payable($inv);
                $before ? $openingCharges += $amount : $periodDebit += $amount;
            }
            // A cash-term invoice with no payment is settled at issue — never a
            // debit nor a credit (same exclusion as the balance).
        }

        foreach ($payments as $p) {
            $amount = $this->signedAmount($p); // net of refunds
            $payDate = $p->pay_date ? Carbon::parse($p->pay_date) : null;
            if ($payDate !== null && $payDate->gt($end)) {
                continue;
            }
            // NULL pay_date → carry-over; else bucket by pay_date vs $start.
            ($payDate === null || $payDate->lt($start))
                ? $openingCredits += $amount
                : $periodCredit += $amount;
        }

        // Every input is a 2dp value, so each sum is a true multiple of 0.01 (bar
        // float dust round() removes) → opening + debit − credit == closing exactly.
        $opening = round($openingCharges - $openingCredits, 2);
        $debit = round($periodDebit, 2);
        $credit = round($periodCredit, 2);

        return [
            'opening' => $opening,
            'debit' => $debit,
            'credit' => $credit,
            'closing' => round($opening + $debit - $credit, 2),
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
            // MON-5: a πρόχειρο isn't a document on the customer's statement/balance
            // (credit-note drafts, which reduce, are kept via the helper's carve-out;
            // legacy-imported drafts are kept too). Matches DashboardMetrics.
            ->when(true, fn ($q) => InvoiceScope::excludeUnissuedDrafts($q))
            ->orderBy('invoices.issued_at', 'asc')
            ->select(
                'invoices.id',
                'invoices.invcode',
                'invoices.code',
                'invoices.invoice_type_id',
                'invoices.issued_at',
                'invoices.net_total',
                'invoices.gross_total',
                'invoices.payable_total',
                'invoices.mydata_state',
                'invoices.mydata_mark',
                'invoices.credited_invoice_id',
                'invoices.created_at',
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
     * The COLLECTIBLE amount of an invoice row for the AR ledger: payable_total
     * (net+VAT + fees − withholding) when present, else gross_total (rows not yet
     * backfilled). This is the basis for the receivables BALANCE / aging / running
     * balance — NOT the turnover stats (ytd_gross, the yearly gross/net columns),
     * which keep gross_total (the document value).
     */
    private function payable(object $inv): float
    {
        return (float) ($inv->payable_total ?? $inv->gross_total);
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
            ->select('id', 'pay_date', 'amount', 'kind', 'reference', 'invoice_id', 'notes', 'created_at')
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
     * «Αναλυτική» detail for an invoice ledger row whose collectible differs from
     * its document value: «Αξία εγγράφου 1.240,00 € · Παρακράτηση φόρου 200,00 €»
     * (or «Τέλη/φόροι» when the net adjustment ADDS). Returns null when there's
     * nothing to break down (adjustment 0) so the caller can skip the line.
     * The amounts are display-only — the row's debit/credit stay = payable.
     */
    public static function adjustmentDetail(?float $documentGross, float $taxAdjustment, callable $fmt): ?string
    {
        if ($documentGross === null || abs($taxAdjustment) < 0.005) {
            return null;
        }

        $label = $taxAdjustment < 0 ? 'Παρακράτηση φόρου' : 'Τέλη/φόροι';

        return 'Αξία εγγράφου '.$fmt($documentGross).' · '.$label.' '.$fmt(abs($taxAdjustment));
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
                // YTD net/gross = turnover (document value) — stays gross_total.
                $ytdNet += $sign * (float) $inv->net_total;
                $ytdGross += $sign * (float) $inv->gross_total;
            }

            // Balance basis = the collectible (payable_total), so withholding/fees
            // are reflected in what the customer owes.
            if ($isCreditNote) {
                $creditReductions += $this->payable($inv);
            } elseif ($isCreditTerm) {
                $creditTermGross += $this->payable($inv);
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
                $gross = $this->payable($inv);
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
            // Balance breakdown (so the Καρτέλα can EXPLAIN the number): the
            // collectible charges that count toward the balance, minus the credit
            // notes, minus the payments. charges − credit_notes − payments = balance.
            'charges' => round($creditTermGross, 2),
            'credit_notes' => round($creditReductions, 2),
            'payments' => round($totalPaidLifetime, 2),
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
            + (float) $invoices->filter(fn ($inv) => $this->isCreditNote($inv))->sum(fn ($inv) => $this->payable($inv));
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

            $gross = $this->payable($inv);
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
        $paidIds = $this->paidInvoiceIds($payments);

        foreach ($invoices as $inv) {
            $year = (int) Carbon::parse($inv->issued_at)->year;
            // Credit notes carry negative net/gross (they reduce sales +
            // the year-end running balance).
            $sign = $this->isCreditNote($inv) ? -1 : 1;
            // net/gross = turnover (document value); `payable` = the collectible,
            // used only for the year-end running BALANCE (withholding-aware).
            $byYear[$year] ??= ['year' => $year, 'invoice_count' => 0, 'net' => 0.0, 'gross' => 0.0, 'payable' => 0.0, 'paid' => 0.0];
            $byYear[$year]['invoice_count']++;
            $byYear[$year]['net'] += $sign * (float) $inv->net_total;
            $byYear[$year]['gross'] += $sign * (float) $inv->gross_total;
            // The year-end BALANCE must use the SAME credit-term gate as computeStats
            // / the ledger running balance: cash-term invoices with no recorded payment
            // are settled at issue and never enter the receivables balance. Without this
            // gate `year_end_balance` (and the carry-over that reads it) is inflated by
            // every no-payment cash sale — the common Greek-retail case. Turnover
            // (net/gross) still counts every invoice above.
            if ($this->isCreditNote($inv) || $this->isTracked($inv, $paidIds)) {
                $byYear[$year]['payable'] += $sign * $this->payable($inv);
            }
        }

        foreach ($payments as $p) {
            if (! $p->pay_date) {
                continue;
            }
            $year = (int) Carbon::parse($p->pay_date)->year;
            $byYear[$year] ??= ['year' => $year, 'invoice_count' => 0, 'net' => 0.0, 'gross' => 0.0, 'payable' => 0.0, 'paid' => 0.0];
            // Refunds reduce that year's paid (signedAmount).
            $byYear[$year]['paid'] += $this->signedAmount($p);
        }

        ksort($byYear);
        $running = 0.0;
        $out = [];
        foreach ($byYear as $row) {
            // Running balance tracks the collectible (payable), not the turnover gross.
            $running += $row['payable'] - $row['paid'];
            $row['year_end_balance'] = round($running, 2);
            $row['net'] = round($row['net'], 2);
            $row['gross'] = round($row['gross'], 2);
            $row['paid'] = round($row['paid'], 2);
            unset($row['payable']);   // internal accumulator — not part of the row contract
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
    /**
     * A stable within-day ordering key: the record's creation timestamp, so two
     * rows on the SAME business date (a payment + a same-day refund; two payments)
     * order by when they were entered instead of arbitrarily (payments carry no
     * time-of-day — pay_date is a DATE). Falls back to the business date when
     * created_at is absent (legacy rows).
     */
    private function createdSort(?string $createdAt, int $fallback): int
    {
        return $createdAt ? Carbon::parse($createdAt)->timestamp : $fallback;
    }

    /** Display time «HH:MM» for the ledger row, or null when it's a bare date (00:00). */
    private function displayTime(?string $dt): ?string
    {
        if (! $dt) {
            return null;
        }
        $c = Carbon::parse($dt);

        return $c->format('H:i:s') === '00:00:00' ? null : $c->format('H:i');
    }

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
            // AR-ledger debit/credit = the collectible (net of withholding), so the
            // running balance stays consistent with the receivables balance. The
            // document value (gross) lives on the invoice + the turnover stats.
            $gross = $this->payable($inv);
            // «Αναλυτική» display: surface the document value + the [208] tax
            // adjustment (payable − gross: −withholding/κρατήσεις, +τέλη/φόροι) so the
            // Καρτέλα shows BOTH the invoice value and the παρακράτηση, WITHOUT
            // touching the money: debit/credit stay = payable, so the running
            // balance + the paid/unpaid filters are unchanged. 0 = nothing to detail.
            $documentGross = round((float) $inv->gross_total, 2);
            $taxAdjustment = round($gross - $documentGross, 2);
            $events[] = [
                'date_sort' => Carbon::parse($inv->issued_at)->timestamp,
                'created_sort' => $this->createdSort($inv->created_at ?? null, Carbon::parse($inv->issued_at)->timestamp),
                'time' => $this->displayTime($inv->issued_at),
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
                'document_gross' => $documentGross,
                'tax_adjustment' => $taxAdjustment,
                'mydata_state' => $inv->mydata_state,
                'mydata_mark' => $inv->mydata_mark,
                'is_credit_term' => ! $isCreditNote && $this->isTracked($inv, $paidIds),
                // Display-only «Κατάσταση» hints (they do NOT touch the money math):
                // whether the document is cash- or credit-term, and whether a real
                // payment row is allocated to it — so the operator SEES why the
                // running balance did or didn't move. A cash-term invoice with no
                // payment is settled-at-issue (excluded from the balance); a
                // cash-term invoice WITH a payment posts both its debit and that
                // payment (net zero) — never double-counted either way.
                // Credit notes sit in the Πίστωση column and aren't a «χρέωση» with a
                // term — leave their «Κατάσταση» blank (the badge is invoice-only).
                'payment_term' => $isCreditNote ? null : (((int) ($inv->due_days ?? 0)) > 0 ? 'credit' : 'cash'),
                'has_payment' => ! $isCreditNote && isset($paidIds[(int) $inv->id]),
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

            // #377: a zero/NULL-amount payment (legacy ETL raw-insert bypassed the
            // form's minValue(0.01) guard) would otherwise render as an EMPTY ledger
            // row (blank Χρέωση + blank Πίστωση). Skip it — it contributes 0 to the
            // running balance and to every total, so dropping the row changes no figure.
            if ((float) $p->amount <= 0.0) {
                continue;
            }

            // A refund (money OUT, back to the customer) is the reverse of a
            // payment: a DEBIT that raises the balance again. Always an
            // individual row — never folded into an έμβασμα group.
            if (($p->kind ?? 'payment') === 'refund') {
                $events[] = [
                    'date_sort' => Carbon::parse($p->pay_date)->timestamp,
                    'created_sort' => $this->createdSort($p->created_at ?? null, Carbon::parse($p->pay_date)->timestamp),
                    'time' => $this->displayTime($p->created_at ?? null),
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
                    'created_sort' => $this->createdSort($p->created_at ?? null, Carbon::parse($p->pay_date)->timestamp),
                    'time' => $this->displayTime($p->created_at ?? null),
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
            $earliestCreated = null;
            $sum = 0.0;
            $allocations = [];
            $isReceipt = false; // any allocation against an invoice → «Έμβασμα»

            foreach ($group as $p) {
                $ts = Carbon::parse($p->pay_date)->timestamp;
                if ($earliest === null || $ts < $earliest) {
                    $earliest = $ts;
                }
                $cts = $this->createdSort($p->created_at ?? null, $ts);
                if ($earliestCreated === null || $cts < $earliestCreated) {
                    $earliestCreated = $cts;
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
                'created_sort' => $earliestCreated ?? $earliest,
                'time' => $this->displayTime(Carbon::createFromTimestamp($earliestCreated ?? $earliest)->toDateTimeString()),
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

        // Explicit, deterministic ordering key: date, then creation order, then a
        // stable (type, id, reference) tiebreak. Same-date rows are common in imported
        // data (every ETL payment shares one created_at artifact timestamp), so relying
        // on PHP's sort stability alone was fragile — this pins the order outright.
        $sortKey = static fn (array $e): array => [
            $e['date_sort'],
            $e['created_sort'],
            (string) $e['type'],
            (int) ($e['invoice_id'] ?? $e['payment_id'] ?? 0),
            (string) ($e['reference'] ?? ''),
        ];

        // Walk oldest-first to compute the running balance.
        usort($events, static fn ($a, $b) => $sortKey($a) <=> $sortKey($b));
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

        // Newest first for display — same key, reversed, so the LATER of two
        // same-date rows sits on top (its running balance is the current one).
        usort($events, static fn ($a, $b) => $sortKey($b) <=> $sortKey($a));

        // Strip the internal sort cols from the returned shape - view doesn't need them.
        foreach ($events as &$e) {
            unset($e['date_sort'], $e['created_sort'], $e['is_credit_term']);
            $e['net'] = $e['debit'];   // for backward-compat / view convenience
            // Uniform shape: payment/refund rows carry no document breakdown and no
            // cash/credit «Κατάσταση» (that hint is invoice-only).
            $e['document_gross'] ??= null;
            $e['tax_adjustment'] ??= 0.0;
            $e['payment_term'] ??= null;
            $e['has_payment'] ??= false;
        }
        unset($e);

        return $events;
    }
}
