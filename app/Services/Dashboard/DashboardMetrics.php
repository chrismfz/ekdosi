<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\InvoiceScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-scoped aggregations behind the operator dashboard widgets.
 *
 * Design rules (see the dashboard widgets that consume this):
 *  - EVERY query filters by the tenant's company_id. Invoice has no
 *    global tenant scope (tracked deferral), so widgets can't rely on
 *    Filament's resource-layer scoping — we scope here, explicitly.
 *  - Revenue EXCLUDES cancelled invoices (mydata_state='CANCELLED')
 *    AND credit notes (credited_invoice_id set — they carry positive
 *    gross and would double-count as income). Drafts / never-filed
 *    (mydata_state IS NULL) and VALID sales both count. (Receivables are
 *    netted for credits separately in outstandingReceivables.)
 *  - Output VAT = gross_total - net_total. We do NOT track input
 *    (expense) VAT, so the dashboard's "VAT" is the OUTPUT side only —
 *    a ΦΠΑ ballpark, not the net liability. The widget labels say so.
 *  - Outstanding receivables uses the same credit-term rule as the
 *    customer ledger: only payment_method.due_days > 0 invoices count;
 *    cash-term (due_days = 0 or no payment method) are settled at issue.
 *  - Everything aggregates in SQL (GROUP BY on the existing
 *    (company_id, issued_at) index), never by loading rows into PHP —
 *    the post-ETL invoice table can be 100K+ rows per tenant.
 */
class DashboardMetrics
{
    public function __construct(private readonly Company $tenant) {}

    /**
     * Net / gross / VAT / count of non-cancelled invoices issued in
     * the half-open-ish window [$start, $end] (inclusive bounds —
     * callers pass startOfMonth()/endOfMonth() etc.).
     */
    public function income(CarbonInterface $start, CarbonInterface $end): IncomeFigure
    {
        $row = $this->baseInvoices()
            ->where('issued_at', '>=', $start)
            ->where('issued_at', '<=', $end)
            ->selectRaw('COALESCE(SUM(net_total), 0) net, COALESCE(SUM(gross_total), 0) gross, COUNT(*) cnt')
            ->first();

        $net = (float) ($row->net ?? 0);
        $gross = (float) ($row->gross ?? 0);

        return new IncomeFigure(
            net: round($net, 2),
            gross: round($gross, 2),
            vat: round($gross - $net, 2),
            count: (int) ($row->cnt ?? 0),
        );
    }

    /**
     * MON-2 (AUDIT): the OUTPUT-side figure for VAT LIABILITY — sales in the
     * window NET of credit notes issued in the window. Both carry positive
     * magnitudes, and a credit note REFUNDS output VAT, so it must be
     * SUBTRACTED (not merely excluded like income() does for gross turnover).
     *
     * income() reports gross sales turnover (credit notes excluded) for the
     * revenue tiles; THIS is what the ΦΠΑ-εκροών−εισροών report ("πόσο ΦΠΑ θα
     * χρωστάμε") needs, and it matches the net-VAT semantics already used by
     * LedgerBook::vatBalance() and the customer Καρτέλα (which sign-flip credit
     * notes). Using income() there over-declared output VAT whenever a credit
     * note existed (a €124 sale + its full credit read as €24 output VAT vs the
     * true €0).
     */
    public function outputForVat(CarbonInterface $start, CarbonInterface $end): IncomeFigure
    {
        $agg = 'COALESCE(SUM(net_total), 0) net, COALESCE(SUM(gross_total), 0) gross, COUNT(*) cnt';

        $sales = $this->baseInvoices()
            ->where('issued_at', '>=', $start)->where('issued_at', '<=', $end)
            ->selectRaw($agg)->first();

        $credits = $this->creditNotesQuery()
            ->where('issued_at', '>=', $start)->where('issued_at', '<=', $end)
            ->selectRaw($agg)->first();

        $net = (float) ($sales->net ?? 0) - (float) ($credits->net ?? 0);
        $gross = (float) ($sales->gross ?? 0) - (float) ($credits->gross ?? 0);

        return new IncomeFigure(
            net: round($net, 2),
            gross: round($gross, 2),
            vat: round($gross - $net, 2),
            // Both sales and credit notes are issued output documents in the window.
            count: (int) ($sales->cnt ?? 0) + (int) ($credits->cnt ?? 0),
        );
    }

    /**
     * Outstanding receivables across ALL customers:
     *   Σ(credit-term, non-cancelled invoice gross − credited_total)
     *   − Σ(all non-trashed payments)
     * Credit notes (credited_invoice_id set) are excluded from the base
     * — they're reductions, applied via the original's credited_total
     * cache, not receivables of their own. Payments are subtracted
     * tenant-wide (allocated + on-account both reduce what's owed),
     * matching the CustomerLedgerBuilder balance summed across customers.
     * Can be negative if customers carry credit balances; real figure.
     */
    public function outstandingReceivables(): float
    {
        // Owed base = credit-term invoices PLUS any cash-term invoice that
        // carries a recorded payment (the money-trail exception — it then nets
        // to zero against its payment, so the total is unchanged in the common
        // case but stays consistent with InvoiceBalance + the ledger). LEFT join
        // so a no-payment-method invoice that has payments still qualifies.
        $base = DB::table('invoices')
            ->leftJoin('payment_methods', 'invoices.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.company_id', $this->tenant->id)
            ->whereNull('invoices.deleted_at')
            ->where(function ($q) {
                $q->where('payment_methods.due_days', '>', 0)
                    ->orWhereExists(function ($s) {
                        $s->from('payments')
                            ->whereColumn('payments.invoice_id', 'invoices.id')
                            ->whereNull('payments.deleted_at');
                    });
            });

        // MON-9: credit notes aren't receivables of their own — exclude both the
        // correlated (credited_invoice_id) and the standalone legacy (is_credit
        // type) shape. Safe unqualified here: the only join is payment_methods,
        // which carries neither credited_invoice_id nor invoice_type_id.
        InvoiceScope::excludeCreditNotes($base);

        // Receivable base = payable_total (collectible) per row, gross_total fallback
        // for not-yet-backfilled rows. Revenue/turnover sums elsewhere stay on gross_total.
        $row = InvoiceScope::live($base, 'invoices.')
            ->selectRaw('COALESCE(SUM(COALESCE(invoices.payable_total, invoices.gross_total)), 0) - COALESCE(SUM(invoices.credited_total), 0) AS net_owed')
            ->first();

        $netOwed = (float) ($row->net_owed ?? 0);

        // Refunds count NEGATIVE — money returned raises receivables again.
        $totalPaid = (float) DB::table('payments')
            ->where('company_id', $this->tenant->id)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net_paid')
            ->value('net_paid');

        return round($netOwed - $totalPaid, 2);
    }

    /**
     * Count of invoices that still need filing at AADE: no VALID myDATA
     * MARK yet (mydata_state IS NULL) AND created in the new app
     * (legacy_id IS NULL). The legacy_id filter is essential — the ETL
     * imports thousands of pre-myDATA legacy invoices with a NULL state
     * (MigrateFromFirebird::copyInvoices), and those are NOT a live
     * backlog: they predate myDATA and will never be filed. Counting
     * them would make the badge a permanently-inflated, never-converging
     * number for exactly the Greek tenants it targets. The widget only
     * surfaces this for gr-mydata tenants.
     */
    public function unfiledCount(): int
    {
        return (int) DB::table('invoices')
            ->where('company_id', $this->tenant->id)
            ->whereNull('deleted_at')
            ->whereNull('mydata_state')
            ->whereNull('legacy_id')
            ->where('local_status', '!=', 'cancelled')   // a cancelled draft is not a filing backlog
            ->count();
    }

    /**
     * Net + VAT per month for the last $months calendar months
     * (oldest first, INCLUDING the current month). Always returns a
     * dense series — months with no invoices come back as zeros so the
     * chart x-axis is continuous.
     *
     * @param  CarbonInterface|null  $end  Anchor the trailing window on
     *                                     this month's end instead of "now" — lets the period filter
     *                                     shift the 12-month trend. Null = up to the current month
     *                                     (the default headline behaviour).
     * @return list<array{key: string, label: string, net: float, vat: float}>
     */
    public function monthlyIncome(int $months = 12, ?CarbonInterface $end = null): array
    {
        // subMonthsNoOverflow: a plain subMonths() viewed on the 29th-31st
        // overflows a short month and shifts the whole window forward by
        // one (dropping a real month, appending a future zero one).
        $anchor = ($end ? Carbon::parse($end) : Carbon::now())->startOfMonth();
        $start = $anchor->copy()->subMonthsNoOverflow($months - 1);
        $expr = $this->monthKeyExpr();

        $rows = $this->baseInvoices()
            ->where('issued_at', '>=', $start)
            ->where('issued_at', '<=', $anchor->copy()->endOfMonth())
            ->selectRaw("$expr as ym, COALESCE(SUM(net_total), 0) net, COALESCE(SUM(gross_total), 0) gross")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $out = [];
        $cursor = $start->copy();
        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');
            $row = $rows->get($key);
            $net = (float) ($row->net ?? 0);
            $gross = (float) ($row->gross ?? 0);
            $out[] = [
                'key' => $key,
                'label' => $cursor->format('m/Y'),
                'net' => round($net, 2),
                'vat' => round($gross - $net, 2),
            ];
            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * Cumulative running net per month (1..12) for a single year — the
     * series for the year-over-year comparison chart. Index 0 = January
     * cumulative, index 11 = December cumulative (= full-year net).
     * Months beyond "now" in the current year stay flat at the last
     * real value rather than dropping to zero, so the YoY lines compare
     * like-for-like up to today.
     *
     * @return list<float> 12 cumulative values
     */
    public function cumulativeNetByMonth(int $year): array
    {
        $expr = $this->monthNumberExpr();

        $rows = $this->baseInvoices()
            ->whereBetween('issued_at', ["$year-01-01 00:00:00", "$year-12-31 23:59:59"])
            ->selectRaw("$expr as m, COALESCE(SUM(net_total), 0) net")
            ->groupBy('m')
            ->get()
            ->keyBy(fn ($r) => (int) $r->m);

        $cumulative = [];
        $running = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $running += (float) ($rows->get($m)->net ?? 0);
            $cumulative[] = round($running, 2);
        }

        return $cumulative;
    }

    /**
     * Top-customers Eloquent query for [$start, $end]: Customer rows
     * with a `gross_ytd` (summed non-cancelled invoice gross in the
     * window) and `invoices_ytd` count, ordered by gross desc.
     *
     * Returns an Eloquent builder (NOT a result set) so the Filament
     * TableWidget can hand it straight to ->query(); tests call ->get().
     * Eloquent (not the DB::table aggregate used elsewhere here) because
     * Filament tables require a model query.
     *
     * @return Builder<Customer>
     */
    public function topCustomersQuery(CarbonInterface $start, CarbonInterface $end, int $limit = 10)
    {
        $window = function ($q) use ($start, $end): void {
            $q->where('issued_at', '>=', $start)
                ->where('issued_at', '<=', $end);
            // MON-9: exclude credit notes from sales — correlated (credited_invoice_id)
            // AND standalone/legacy (invoice_types.is_credit), matching the ledger.
            InvoiceScope::excludeCreditNotes($q);
            InvoiceScope::live($q);
        };

        return Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            // whereHas keeps out customers with no invoices in the window:
            // without it, withSum yields NULL gross_ytd for them and they
            // pad the bottom of the top-N with blank totals.
            ->whereHas('invoices', $window)
            ->withSum(['invoices as gross_ytd' => $window], 'gross_total')
            ->withCount(['invoices as invoices_ytd' => $window])
            ->orderByDesc('gross_ytd')
            ->limit($limit);
    }

    /**
     * Customers who owe money, highest balance first — the per-customer
     * breakdown behind the "Ανεξόφλητα (πιστωτικά)" headline. Each row
     * carries an `outstanding_balance` aliased column (see
     * Customer::scopeWithOutstandingBalance, which mirrors
     * outstandingReceivables()'s math, so Σ(positive balances) reconciles
     * with the headline). Returns an Eloquent builder for the Filament
     * TableWidget; tests call ->get().
     *
     * @return Builder<Customer>
     */
    public function topDebtorsQuery(int $limit = 10)
    {
        return Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->onlyDebtors()
            ->orderByDesc('outstanding_balance')
            ->limit($limit);
    }

    /**
     * IDs of this tenant's customers who currently owe money — the set
     * behind the Customers-list "Με υπόλοιπο" filter that the headline
     * card links into. Unbounded (no limit): the filter needs ALL
     * debtors, not just the top N.
     *
     * @return list<int>
     */
    public function debtorIds(): array
    {
        return Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->onlyDebtors()
            ->pluck('customers.id')
            ->all();
    }

    /**
     * Distinct calendar years that have at least one (live, non-credit)
     * sales invoice, newest first — the option set for the Reports page
     * year selectors. The current year is always included even on a fresh
     * tenant so the selector is never empty.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $expr = $this->yearExpr();

        $years = $this->baseInvoices()
            ->selectRaw("$expr as y")
            ->groupBy('y')
            ->orderByRaw('y DESC')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->all();

        $current = (int) Carbon::now()->year;
        if (! in_array($current, $years, true)) {
            array_unshift($years, $current);
            rsort($years);
        }

        return $years;
    }

    /**
     * Net + output VAT per calendar month (1..12) for a single year, as a
     * DENSE 12-element list (empty months come back zeroed). The non-
     * cumulative twin of cumulativeNetByMonth — feeds the "τζίρος ανά μήνα"
     * bar chart for an arbitrary year.
     *
     * @return list<array{month: int, net: float, vat: float}>
     */
    public function monthlyForYear(int $year): array
    {
        $expr = $this->monthNumberExpr();

        $rows = $this->baseInvoices()
            ->whereBetween('issued_at', ["$year-01-01 00:00:00", "$year-12-31 23:59:59"])
            ->selectRaw("$expr as m, COALESCE(SUM(net_total), 0) net, COALESCE(SUM(gross_total), 0) gross")
            ->groupBy('m')
            ->get()
            ->keyBy(fn ($r) => (int) $r->m);

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $net = (float) ($rows->get($m)->net ?? 0);
            $gross = (float) ($rows->get($m)->gross ?? 0);
            $out[] = [
                'month' => $m,
                'net' => round($net, 2),
                'vat' => round($gross - $net, 2),
            ];
        }

        return $out;
    }

    /**
     * Per-year totals for the trailing $count years (oldest first,
     * including $endYear which defaults to the current year). Dense:
     * years with no invoices come back zeroed so the bar chart x-axis is
     * continuous and the YoY growth read is honest.
     *
     * @return list<array{year: int, net: float, gross: float, vat: float, count: int}>
     */
    public function yearlyTotals(int $count = 5, ?int $endYear = null): array
    {
        $endYear ??= (int) Carbon::now()->year;
        $startYear = $endYear - $count + 1;
        $expr = $this->yearExpr();

        $rows = $this->baseInvoices()
            ->whereBetween('issued_at', ["$startYear-01-01 00:00:00", "$endYear-12-31 23:59:59"])
            ->selectRaw("$expr as y, COALESCE(SUM(net_total), 0) net, COALESCE(SUM(gross_total), 0) gross, COUNT(*) cnt")
            ->groupBy('y')
            ->get()
            ->keyBy(fn ($r) => (int) $r->y);

        $out = [];
        for ($y = $startYear; $y <= $endYear; $y++) {
            $net = (float) ($rows->get($y)->net ?? 0);
            $gross = (float) ($rows->get($y)->gross ?? 0);
            $out[] = [
                'year' => $y,
                'net' => round($net, 2),
                'gross' => round($gross, 2),
                'vat' => round($gross - $net, 2),
                'count' => (int) ($rows->get($y)->cnt ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Headline KPIs for the Reports scorecard, for a single year.
     *
     * The year's window is [Jan 1 .. Dec 31], but for the CURRENT year it
     * is clamped to "now" so the figure is year-to-date — and the YoY
     * comparison uses the SAME calendar span shifted one year back
     * (priorStart/priorEnd), so "+12% vs πέρσι" compares Jan–May to Jan–May,
     * not Jan–May to a full prior year. DSO + receivables are a "now"
     * snapshot (receivables have no year), DSO annualised over trailing 365
     * days of sales.
     *
     * @return array{
     *   year: int, net: float, gross: float, vat: float, count: int,
     *   priorNet: float, yoyPct: float|null,
     *   avgMonthlyNet: float, avgInvoiceNet: float,
     *   creditRatioPct: float, creditGross: float,
     *   topCustomerName: string|null, topCustomerShare: float|null,
     *   receivables: float, dsoDays: int|null
     * }
     */
    public function kpiSummary(int $year): array
    {
        $now = Carbon::now();
        $currentYear = (int) $now->year;

        $periodStart = Carbon::create($year, 1, 1)->startOfDay();
        $periodEnd = Carbon::create($year, 12, 31)->endOfDay();
        if ($year === $currentYear) {
            $periodEnd = $now->copy();
        } elseif ($year > $currentYear) {
            // Future year selected — nothing to show, avoid a bogus window.
            $periodEnd = $periodStart->copy();
        }

        $cur = $this->income($periodStart, $periodEnd);

        // Like-for-like prior period (same span, one year earlier).
        // subYearNoOverflow (NOT subYear) on purpose: on a Feb-29 "now" it
        // clamps the prior bound to Feb 28 — the prior window is one day
        // shorter (a negligible rounded-% effect), vs subYear() which would
        // overflow Feb 29 → Mar 1 and silently shift the whole window.
        $prior = $this->income(
            $periodStart->copy()->subYearNoOverflow(),
            $periodEnd->copy()->subYearNoOverflow(),
        );
        $yoy = $prior->net > 0.005
            ? round(($cur->net - $prior->net) / $prior->net * 100, 1)
            : null;

        $monthsElapsed = $year === $currentYear ? (int) $now->month : ($year > $currentYear ? 0 : 12);
        $avgMonthly = $monthsElapsed > 0 ? round($cur->net / $monthsElapsed, 2) : 0.0;
        $avgInvoice = $cur->count > 0 ? round($cur->net / $cur->count, 2) : 0.0;

        // Credit notes issued in the window (positive gross) as a share of
        // sales gross — a returns / correction-rate quality signal.
        $creditGross = (float) $this->creditNotesQuery()
            ->where('issued_at', '>=', $periodStart)
            ->where('issued_at', '<=', $periodEnd)
            ->sum('gross_total');
        $creditRatio = $cur->gross > 0.005 ? round($creditGross / $cur->gross * 100, 1) : 0.0;

        // Concentration risk: the single biggest customer's share of sales.
        $top = $this->topCustomersQuery($periodStart, $periodEnd, 1)->first();
        $topShare = ($top && $cur->gross > 0.005)
            ? round(((float) $top->gross_ytd) / $cur->gross * 100, 1)
            : null;

        // DSO snapshot: receivables / (trailing-365-day sales per day).
        $receivables = $this->outstandingReceivables();
        $trailing = $this->income($now->copy()->subYearNoOverflow(), $now);
        $perDay = $trailing->gross / 365.0;
        $dso = $perDay > 0.005 ? (int) round($receivables / $perDay) : null;

        return [
            'year' => $year,
            'net' => $cur->net,
            'gross' => $cur->gross,
            'vat' => $cur->vat,
            'count' => $cur->count,
            'priorNet' => $prior->net,
            'yoyPct' => $yoy,
            'avgMonthlyNet' => $avgMonthly,
            'avgInvoiceNet' => $avgInvoice,
            'creditRatioPct' => $creditRatio,
            'creditGross' => round($creditGross, 2),
            'topCustomerName' => $top?->name,
            'topCustomerShare' => $topShare,
            'receivables' => $receivables,
            'dsoDays' => $dso,
        ];
    }

    /**
     * Net turnover per (year, month) for the trailing $years years ending
     * on $endYear (default current) — the matrix behind the seasonality
     * heatmap. Dense: every year row has all 12 months (zeros where empty).
     * `max` is the largest single cell, for colour scaling in the view.
     *
     * @return array{years: list<int>, matrix: array<int, array<int, float>>, max: float}
     */
    public function netByMonthMatrix(int $years = 4, ?int $endYear = null): array
    {
        $endYear ??= (int) Carbon::now()->year;
        $startYear = $endYear - $years + 1;
        $monthExpr = $this->monthNumberExpr();
        $yearExpr = $this->yearExpr();

        $rows = $this->baseInvoices()
            ->whereBetween('issued_at', ["$startYear-01-01 00:00:00", "$endYear-12-31 23:59:59"])
            ->selectRaw("$yearExpr as y, $monthExpr as m, COALESCE(SUM(net_total), 0) net")
            ->groupBy('y', 'm')
            ->get();

        $matrix = [];
        $yearsList = [];
        for ($y = $startYear; $y <= $endYear; $y++) {
            $yearsList[] = $y;
            $matrix[$y] = array_fill(1, 12, 0.0);
        }

        $max = 0.0;
        foreach ($rows as $r) {
            $y = (int) $r->y;
            $m = (int) $r->m;
            if (isset($matrix[$y][$m])) {
                $net = round((float) $r->net, 2);
                $matrix[$y][$m] = $net;
                $max = max($max, $net);
            }
        }

        return ['years' => $yearsList, 'matrix' => $matrix, 'max' => $max];
    }

    /**
     * The seasonal shape of the business: average net per calendar month
     * over the trailing $years years ending on $endYear (default = last
     * COMPLETED year, so a partial current year doesn't distort the shape).
     * Averaged only over "active" years (years with any turnover), so a
     * brand-new tenant's empty back-years don't halve the averages.
     *
     *   - monthlyAvg[1..12] : average net for that month
     *   - index[1..12]      : monthlyAvg / overall monthly average
     *                         (1.0 = an average month; >1 = a peak month)
     *
     * @return array{yearsUsed: list<int>, monthlyAvg: array<int, float>, index: array<int, float>, overallAvg: float}
     */
    public function seasonalProfile(int $years = 3, ?int $endYear = null): array
    {
        $endYear ??= (int) Carbon::now()->year - 1;
        $data = $this->netByMonthMatrix($years, $endYear);

        $activeYears = array_values(array_filter(
            $data['years'],
            fn (int $y): bool => array_sum($data['matrix'][$y]) > 0.005,
        ));
        $n = count($activeYears);

        $monthlyAvg = array_fill(1, 12, 0.0);
        if ($n > 0) {
            for ($m = 1; $m <= 12; $m++) {
                $sum = 0.0;
                foreach ($activeYears as $y) {
                    $sum += $data['matrix'][$y][$m];
                }
                $monthlyAvg[$m] = round($sum / $n, 2);
            }
        }

        $overall = array_sum($monthlyAvg) / 12;
        $index = array_fill(1, 12, 1.0);
        if ($overall > 0.005) {
            for ($m = 1; $m <= 12; $m++) {
                $index[$m] = round($monthlyAvg[$m] / $overall, 4);
            }
        }

        return [
            'yearsUsed' => $activeYears,
            'monthlyAvg' => $monthlyAvg,
            'index' => $index,
            'overallAvg' => round($overall, 2),
        ];
    }

    /**
     * Seasonal-naive projection for NEXT calendar year (now-anchored, not
     * filter-driven — "next year" is a fixed concept). Deliberately simple
     * and explainable, NOT a statistical model:
     *
     *   total   = last completed year's net × (1 + growth)
     *   growth  = average YoY growth over the trailing completed years,
     *             clamped to [-50%, +100%] so a single freak year can't
     *             produce an absurd extrapolation (0 if <2 years of history)
     *   monthly = total distributed by the seasonal index (seasonalProfile)
     *
     * Fallback base = trailing-12-months net when last year is empty. The
     * widget labels this an εκτίμηση, never a commitment.
     *
     * @return array{nextYear: int, baseYear: int, baseTotal: float, growthPct: float, total: float, monthly: list<float>, hasHistory: bool}
     */
    public function projectNextYear(int $historyYears = 3): array
    {
        $now = Carbon::now();
        $currentYear = (int) $now->year;
        $baseYear = $currentYear - 1;

        $index = $this->seasonalProfile($historyYears, $baseYear)['index'];
        $sumIndex = array_sum($index) ?: 12.0;

        // Base level: last completed year's net, or trailing 12 months if empty.
        $baseTotal = $this->yearlyTotals(1, $baseYear)[0]['net'] ?? 0.0;
        if ($baseTotal <= 0.005) {
            $baseTotal = $this->income($now->copy()->subYearNoOverflow(), $now)->net;
        }

        // Average YoY growth over the trailing completed years.
        $totals = array_column($this->yearlyTotals($historyYears + 1, $baseYear), 'net');
        $ratios = [];
        for ($i = 1; $i < count($totals); $i++) {
            if ($totals[$i - 1] > 0.005) {
                $ratios[] = $totals[$i] / $totals[$i - 1] - 1;
            }
        }
        $growth = $ratios === [] ? 0.0 : array_sum($ratios) / count($ratios);
        $growth = max(-0.5, min(1.0, $growth));

        $total = round($baseTotal * (1 + $growth), 2);
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[] = round($total * ($index[$m] / $sumIndex), 2);
        }

        return [
            'nextYear' => $currentYear + 1,
            'baseYear' => $baseYear,
            'baseTotal' => round($baseTotal, 2),
            'growthPct' => round($growth * 100, 1),
            'total' => $total,
            'monthly' => $monthly,
            'hasHistory' => $baseTotal > 0.005,
        ];
    }

    // ---- internals ------------------------------------------------------

    /**
     * Base query: this tenant's non-deleted, non-cancelled SALES
     * invoices. NULL mydata_state (draft / never filed) is NOT cancelled,
     * so it stays in — note the explicit NULL handling (a bare
     * `!= 'CANCELLED'` would drop NULL rows in SQL three-valued logic).
     * Credit notes (credited_invoice_id set) are EXCLUDED — they carry
     * positive gross and would otherwise double-count as income (a sale +
     * its credit would read as 2× revenue instead of net-zero).
     */
    private function baseInvoices()
    {
        $q = DB::table('invoices')
            ->where('company_id', $this->tenant->id)
            ->whereNull('deleted_at');

        // MON-9: exclude correlated AND standalone/legacy (is_credit) credit notes.
        InvoiceScope::excludeCreditNotes($q);

        return InvoiceScope::live($q);
    }

    /**
     * Credit notes (credited_invoice_id set), live + tenant-scoped. They
     * carry POSITIVE gross; baseInvoices() deliberately excludes them from
     * income, so the credit-rate KPI reads them from here instead.
     */
    private function creditNotesQuery()
    {
        $q = DB::table('invoices')
            ->where('company_id', $this->tenant->id)
            ->whereNull('deleted_at');

        // MON-9: the complement of baseInvoices() — correlated OR is_credit type.
        InvoiceScope::onlyCreditNotes($q);

        return InvoiceScope::live($q);
    }

    /** Numeric year expression, portable across sqlite (tests) + MariaDB. */
    private function yearExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', issued_at) AS INTEGER)"
            : 'YEAR(issued_at)';
    }

    /** 'YYYY-MM' bucket expression, portable across sqlite (tests) + MariaDB. */
    private function monthKeyExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', issued_at)"
            : "DATE_FORMAT(issued_at, '%Y-%m')";
    }

    /** Numeric month (1..12) expression, portable across sqlite + MariaDB. */
    private function monthNumberExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', issued_at) AS INTEGER)"
            : 'MONTH(issued_at)';
    }
}
