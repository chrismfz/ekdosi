<?php

namespace App\Services\Assistant;

use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Phase 2c (ε): the AI «Βοηθός» usage/cost report — «ποιος πληρώνει, ποιος κοντά
 * στο όριο». A read-only aggregation over `ai_usage_log` (the same table that is
 * the source of truth for the monthly cap, see {@see AiUsageMeter}); it adds NO
 * new data, only a surface.
 *
 * CROSS-TENANT by design: this is a super_admin governance/billing view, so every
 * query DELIBERATELY drops the {@see CompanyScope} global scope (per the CLAUDE.md
 * rule: an all-tenant sweep declares itself with `withoutGlobalScope`). The page
 * that renders it (the `App\Filament\Pages\AiUsage` page) is hard-gated to a
 * system super_admin — a per-tenant view for company_admin is a tracked follow-up.
 *
 * Costs are OUR estimate in USD (from `cost_estimate`, computed by {@see AiPricing}
 * against the per-model price map); token counts are authoritative.
 */
class AiUsageReport
{
    /** Soft-warn threshold — mirrors AiUsageMeter::WARN_AT so the badge matches the runner. */
    private const WARN_AT = 0.80;

    public function __construct(private readonly AiUsageMeter $meter = new AiUsageMeter) {}

    /** Greek month names for deterministic labels (no locale dependency). */
    private const MONTHS_EL = [
        1 => 'Ιανουαρίου', 2 => 'Φεβρουαρίου', 3 => 'Μαρτίου', 4 => 'Απριλίου',
        5 => 'Μαΐου', 6 => 'Ιουνίου', 7 => 'Ιουλίου', 8 => 'Αυγούστου',
        9 => 'Σεπτεμβρίου', 10 => 'Οκτωβρίου', 11 => 'Νοεμβρίου', 12 => 'Δεκεμβρίου',
    ];

    /**
     * The full report for one calendar month (`Y-m`), plus a trailing trend.
     *
     * @return array{
     *   month:string, monthLabel:string, isCurrentMonth:bool,
     *   companies:list<array<string,mixed>>, users:list<array<string,mixed>>,
     *   trend:list<array{month:string,label:string,billable:int,cost:float}>,
     *   totals:array{input:int,output:int,cacheRead:int,cacheWrite:int,billable:int,requests:int,cost:float}
     * }
     */
    public function forMonth(string $month, int $trendMonths = 6): array
    {
        [$start, $end] = $this->monthBounds($month);
        $isCurrent = $start->isSameMonth(CarbonImmutable::now());

        $companies = $this->perCompany($start, $end, $isCurrent);
        $users = $this->perUser($start, $end);

        $totals = [
            'input' => array_sum(array_column($companies, 'input')),
            'output' => array_sum(array_column($companies, 'output')),
            'cacheRead' => array_sum(array_column($companies, 'cacheRead')),
            'cacheWrite' => array_sum(array_column($companies, 'cacheWrite')),
            'billable' => array_sum(array_column($companies, 'billable')),
            'requests' => array_sum(array_column($companies, 'requests')),
            'cost' => round(array_sum(array_column($companies, 'cost')), 4),
        ];

        return [
            'month' => $start->format('Y-m'),
            'monthLabel' => (self::MONTHS_EL[(int) $start->format('n')] ?? '').' '.$start->format('Y'),
            'isCurrentMonth' => $isCurrent,
            'companies' => $companies,
            'users' => $users,
            'trend' => $this->trend($start, $trendMonths),
            'totals' => $totals,
        ];
    }

    /** @return list<array<string,mixed>> one row per company with usage in the window, cost desc. */
    private function perCompany(CarbonImmutable $start, CarbonImmutable $end, bool $withCap): array
    {
        $grouped = AiUsageLog::query()
            ->withoutGlobalScope(CompanyScope::class)   // cross-tenant governance view (declared)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end) // half-open: no boundary double-count
            ->selectRaw('company_id,
                COALESCE(SUM(input_tokens),0) AS i,
                COALESCE(SUM(output_tokens),0) AS o,
                COALESCE(SUM(cache_read_tokens),0) AS cr,
                COALESCE(SUM(cache_write_tokens),0) AS cw,
                COALESCE(SUM(cost_estimate),0) AS cost,
                COUNT(*) AS reqs')
            ->groupBy('company_id')
            ->get();

        $companies = Company::query()->whereIn('id', $grouped->pluck('company_id'))->get()->keyBy('id');

        $rows = [];
        foreach ($grouped as $g) {
            $billable = (int) $g->i + (int) $g->o;
            $company = $companies->get($g->company_id);
            $cap = ($withCap && $company instanceof Company) ? $this->meter->effectiveCap($company) : null;
            $pct = ($cap !== null && $cap > 0) ? $billable / $cap : null;

            $rows[] = [
                'company_id' => (int) $g->company_id,
                'name' => (string) ($company?->name ?? ('#'.$g->company_id)),
                'input' => (int) $g->i,
                'output' => (int) $g->o,
                'cacheRead' => (int) $g->cr,
                'cacheWrite' => (int) $g->cw,
                'billable' => $billable,
                'requests' => (int) $g->reqs,
                'cost' => round((float) $g->cost, 4),
                'cap' => $cap,
                'pct' => $pct,
                'status' => $this->status($pct),
            ];
        }

        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return $rows;
    }

    /** @return list<array<string,mixed>> per-user usage in the window, cost desc — «ποιος έκαψε το budget». */
    private function perUser(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $grouped = AiUsageLog::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('user_id, company_id,
                COALESCE(SUM(input_tokens + output_tokens),0) AS billable,
                COALESCE(SUM(cost_estimate),0) AS cost,
                COUNT(*) AS reqs')
            ->groupBy('user_id', 'company_id')
            ->get();

        $userNames = User::query()->whereIn('id', $grouped->pluck('user_id')->filter())->pluck('name', 'id');
        $companyNames = Company::query()->whereIn('id', $grouped->pluck('company_id'))->pluck('name', 'id');

        $rows = [];
        foreach ($grouped as $g) {
            $rows[] = [
                'name' => $g->user_id ? (string) ($userNames[$g->user_id] ?? ('Χρήστης #'.$g->user_id)) : 'Σύστημα',
                'company' => (string) ($companyNames[$g->company_id] ?? ('#'.$g->company_id)),
                'billable' => (int) $g->billable,
                'requests' => (int) $g->reqs,
                'cost' => round((float) $g->cost, 4),
            ];
        }

        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return $rows;
    }

    /**
     * Billable tokens + cost per month for the $months ending at $anchor. Computed
     * with a bounded SUM per month (DB-portable: no YEAR()/MONTH() so sqlite tests
     * and MariaDB agree), newest last.
     *
     * @return list<array{month:string,label:string,billable:int,cost:float}>
     */
    private function trend(CarbonImmutable $anchor, int $months): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $anchor->startOfMonth()->subMonths($i);
            $end = $m->addMonth();
            $row = AiUsageLog::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('created_at', '>=', $m)->where('created_at', '<', $end)
                ->selectRaw('COALESCE(SUM(input_tokens + output_tokens),0) AS billable, COALESCE(SUM(cost_estimate),0) AS cost')
                ->first();

            $out[] = [
                'month' => $m->format('Y-m'),
                'label' => mb_substr(self::MONTHS_EL[(int) $m->format('n')] ?? '', 0, 3).' '.$m->format('y'),
                'billable' => (int) ($row->billable ?? 0),
                'cost' => round((float) ($row->cost ?? 0), 4),
            ];
        }

        return $out;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} [start-of-month, start-of-next-month) */
    private function monthBounds(string $month): array
    {
        $start = CarbonImmutable::hasFormat($month, 'Y-m')
            ? CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        return [$start, $start->addMonth()];
    }

    private function status(?float $pct): string
    {
        if ($pct === null) {
            return 'ok';
        }

        return match (true) {
            $pct >= 1.0 => 'blocked',
            $pct >= self::WARN_AT => 'warn',
            default => 'ok',
        };
    }
}
