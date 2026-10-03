<?php

namespace App\Services\Pos;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PosEvent;
use App\Models\PosSession;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\InvoiceScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Αναφορές Ταμείου» — the back-office view of the till for a period: what was
 * sold / returned (per day, payment method, VAT rate, cashier), and every till
 * session with its count. Read-only. The money of a set of documents is THE same
 * calculation as a session's closing report (TillSessions::aggregate).
 *
 * Scope: ISSUED till documents (rung into a session — `pos_session_id`), live
 * (not cancelled at AADE), by their issue date. Sessions by their CLOSING date (the
 * count is the end-of-day act — a till open across midnight belongs to the day it was
 * counted); a still-open session shows in any period from its opening on.
 */
class PosReports
{
    /**
     * @return array{
     *   totals: array<string, mixed>,
     *   difference_total: float,
     *   avg_basket: float,
     *   return_rate: float,
     *   by_day: list<array{date: string, sales_count: int, sales: float, refunds: float, net: float}>,
     *   by_cashier: list<array{user_id: ?int, name: string, sales_count: int, sales: float, refunds_count: int, refunds: float, net: float, discounts: float, sessions_closed: int, difference: float}>,
     *   sessions: Collection<int, PosSession>,
     * }
     */
    public function build(Company $company, CarbonInterface $from, CarbonInterface $to, ?int $cashierId = null): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $docs = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereNotNull('pos_session_id')
            ->where('local_status', 'active')
            ->tap(fn ($q) => InvoiceScope::live($q))
            ->whereBetween('issued_at', [$start, $end])
            ->when($cashierId !== null, fn ($q) => $q->where('pos_cashier_id', $cashierId))
            ->with(['paymentMethod', 'invoiceType', 'lines', 'posCashier'])
            ->orderBy('issued_at')
            ->get();

        $totals = TillSessions::aggregate($docs);

        $byDay = $docs->groupBy(fn (Invoice $i) => $i->issued_at?->toDateString() ?? '')
            ->map(function (Collection $day, string $date): array {
                $a = TillSessions::aggregate($day);

                return ['date' => $date, 'sales_count' => $a['sales_count'], 'sales' => $a['sales_total'], 'refunds' => $a['refunds_total'], 'net' => $a['net_total']];
            })->values()->all();

        $sessions = PosSession::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where(fn ($q) => $q->whereBetween('closed_at', [$start, $end])
                ->orWhere(fn ($open) => $open->whereNull('closed_at')->where('opened_at', '<=', $end)))
            ->when($cashierId !== null, fn ($q) => $q->where(fn ($w) => $w->where('opened_by', $cashierId)->orWhere('closed_by', $cashierId)))
            ->with(['opener', 'closer'])
            ->orderByDesc('opened_at')
            ->get();

        // The cashier's actions that are not documents («Ιστορικό ενεργειών ταμία»).
        // Counted in SQL (a year of open-price lines is a lot of rows); only the ones
        // worth a look are loaded, newest 300.
        $eventScope = fn () => PosEvent::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereBetween('created_at', [$start, $end])
            ->when($cashierId !== null, fn ($q) => $q->where('user_id', $cashierId));
        $eventTotals = $eventScope()->selectRaw('user_id, type, COUNT(*) AS n, SUM(amount) AS amount')
            ->groupBy('user_id', 'type')->get();
        $eventsBy = $eventTotals->groupBy(fn ($row) => (string) ($row->user_id ?? ''));
        $watched = $eventScope()->whereIn('type', self::WATCH)->with(['user', 'product', 'invoice'])
            ->orderByDesc('id')->limit(300)->get();
        $eventUsers = $eventTotals->pluck('user_id')->filter()->unique();
        $eventNames = $eventUsers->isEmpty() ? collect() : User::query()->whereKey($eventUsers->all())->pluck('name', 'id');

        // Per cashier: what they rang (documents) + the counts of the sessions THEY closed —
        // also someone who only closed (a manager counting the drawer): their difference
        // must not vanish from the table.
        $docsBy = $docs->groupBy(fn (Invoice $i) => (string) ($i->pos_cashier_id ?? ''));
        $closers = $sessions->filter(fn (PosSession $s) => ! $s->isOpen() && $s->closed_by !== null)
            ->when($cashierId !== null, fn ($c) => $c->filter(fn (PosSession $s) => (int) $s->closed_by === $cashierId));
        // (plain arrays keyed by id — Collection::merge would renumber numeric keys)
        $names = [];
        foreach ($docs as $doc) {
            $names[(string) $doc->pos_cashier_id] ??= $doc->posCashier?->name;
        }
        foreach ($closers as $closed) {
            $names[(string) $closed->closed_by] ??= $closed->closer?->name;
        }
        foreach ($eventUsers as $userId) {
            $names[(string) $userId] ??= $eventNames[$userId] ?? null;
        }
        $byCashier = collect(array_keys($names))->map(fn ($id) => (string) $id)
            ->map(function (string $userId) use ($docsBy, $closers, $names, $eventsBy): array {
                $a = TillSessions::aggregate($docsBy->get($userId, collect()));
                $closed = $userId === '' ? collect() : $closers->filter(fn (PosSession $s) => (string) $s->closed_by === $userId);

                return [
                    'user_id' => $userId === '' ? null : (int) $userId,
                    'name' => $names[$userId] ?? '— άγνωστος —',
                    'sales_count' => $a['sales_count'],
                    'sales' => $a['sales_total'],
                    'refunds_count' => $a['refunds_count'],
                    'refunds' => $a['refunds_total'],
                    'net' => $a['net_total'],
                    'discounts' => $a['discounts'],
                    'sessions_closed' => $closed->count(),
                    'difference' => round($closed->sum(fn (PosSession $s) => (float) $s->counted_cash - (float) $s->expected_cash), 2),
                ] + self::eventCounts($eventsBy->get($userId, collect()));
            })->sortByDesc('net')->values()->all();

        return [
            'totals' => $totals,
            // Σ count differences: of every closed session — or, filtered by a cashier,
            // of the ones THEY closed (the same rule as their row).
            'difference_total' => round($closers->sum(fn (PosSession $s) => (float) $s->counted_cash - (float) $s->expected_cash), 2),
            'avg_basket' => $totals['sales_count'] > 0 ? round($totals['sales_total'] / $totals['sales_count'], 2) : 0.0,
            'return_rate' => $totals['sales_total'] > 0 ? round(100 * $totals['refunds_total'] / $totals['sales_total'], 1) : 0.0,
            'by_day' => $byDay,
            'by_cashier' => $byCashier,
            'sessions' => $sessions,
            // The actions worth a look (not every open price / X report) — newest first.
            'events' => $watched,
            'event_counts' => self::eventCounts($eventTotals),
        ];
    }

    /** The till actions the owner reviews: things removed / undone / repeated / failed. */
    public const WATCH = [
        PosEvent::CART_CLEARED, PosEvent::ITEM_REMOVED, PosEvent::QTY_REDUCED, PosEvent::DISCOUNT,
        PosEvent::REPRINT, PosEvent::ISSUE_FAILED, PosEvent::RETURN_CANCELLED,
    ];

    /**
     * @param  Collection<int, object>  $rows  aggregated per user × type (n, amount)
     * @return array{cleared: int, cleared_amount: float, removed: int, removed_amount: float, discount_events: int, discount_amount: float, reprints: int, failures: int}
     */
    private static function eventCounts($rows): array
    {
        $of = fn (array $types) => $rows->whereIn('type', $types);
        $n = fn (array $types) => (int) $of($types)->sum('n');
        $sum = fn (array $types) => round((float) $of($types)->sum('amount'), 2);
        $removed = [PosEvent::ITEM_REMOVED, PosEvent::QTY_REDUCED];

        return [
            'cleared' => $n([PosEvent::CART_CLEARED]),
            'cleared_amount' => $sum([PosEvent::CART_CLEARED]),
            'removed' => $n($removed),
            'removed_amount' => $sum($removed),
            'discount_events' => $n([PosEvent::DISCOUNT]),
            'discount_amount' => $sum([PosEvent::DISCOUNT]),
            'reprints' => $n([PosEvent::REPRINT]),
            'failures' => $n([PosEvent::ISSUE_FAILED]),
        ];
    }
}
