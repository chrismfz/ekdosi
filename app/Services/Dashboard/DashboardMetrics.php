<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Support\InvoiceScope;
use Carbon\CarbonInterface;
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
        $base = DB::table('invoices')
            ->join('payment_methods', 'invoices.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.company_id', $this->tenant->id)
            ->whereNull('invoices.deleted_at')
            ->whereNull('invoices.credited_invoice_id')
            ->where('payment_methods.due_days', '>', 0);

        $row = InvoiceScope::live($base, 'invoices.')
            ->selectRaw('COALESCE(SUM(invoices.gross_total), 0) - COALESCE(SUM(invoices.credited_total), 0) AS net_owed')
            ->first();

        $netOwed = (float) ($row->net_owed ?? 0);

        $totalPaid = (float) DB::table('payments')
            ->where('company_id', $this->tenant->id)
            ->whereNull('deleted_at')
            ->sum('amount');

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
     *         this month's end instead of "now" — lets the period filter
     *         shift the 12-month trend. Null = up to the current month
     *         (the default headline behaviour).
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
                'key'   => $key,
                'label' => $cursor->format('m/Y'),
                'net'   => round($net, 2),
                'vat'   => round($gross - $net, 2),
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
     * @return list<float>  12 cumulative values
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
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Customer>
     */
    public function topCustomersQuery(CarbonInterface $start, CarbonInterface $end, int $limit = 10)
    {
        $window = function ($q) use ($start, $end): void {
            $q->where('issued_at', '>=', $start)
                ->where('issued_at', '<=', $end)
                ->whereNull('credited_invoice_id');   // exclude credit notes from sales
            InvoiceScope::live($q);
        };

        return \App\Models\Customer::query()
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
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Customer>
     */
    public function topDebtorsQuery(int $limit = 10)
    {
        return \App\Models\Customer::query()
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
        return \App\Models\Customer::query()
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
                'net'   => round($net, 2),
                'vat'   => round($gross - $net, 2),
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
                'year'  => $y,
                'net'   => round($net, 2),
                'gross' => round($gross, 2),
                'vat'   => round($gross - $net, 2),
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
            'year'             => $year,
            'net'              => $cur->net,
            'gross'            => $cur->gross,
            'vat'              => $cur->vat,
            'count'            => $cur->count,
            'priorNet'         => $prior->net,
            'yoyPct'           => $yoy,
            'avgMonthlyNet'    => $avgMonthly,
            'avgInvoiceNet'    => $avgInvoice,
            'creditRatioPct'   => $creditRatio,
            'creditGross'      => round($creditGross, 2),
            'topCustomerName'  => $top?->name,
            'topCustomerShare' => $topShare,
            'receivables'      => $receivables,
            'dsoDays'          => $dso,
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
            ->whereNull('deleted_at')
            ->whereNull('credited_invoice_id');

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
            ->whereNull('deleted_at')
            ->whereNotNull('credited_invoice_id');

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
