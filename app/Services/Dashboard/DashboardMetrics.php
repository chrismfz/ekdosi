<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Support\InvoiceScope;
use Carbon\CarbonInterface;
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
     * @return list<array{key: string, label: string, net: float, vat: float}>
     */
    public function monthlyIncome(int $months = 12): array
    {
        // subMonthsNoOverflow: a plain subMonths() viewed on the 29th-31st
        // overflows a short month and shifts the whole window forward by
        // one (dropping a real month, appending a future zero one).
        $start = now()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $expr = $this->monthKeyExpr();

        $rows = $this->baseInvoices()
            ->where('issued_at', '>=', $start)
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
