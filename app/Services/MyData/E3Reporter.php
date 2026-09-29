<?php

namespace App\Services\MyData;

use App\Models\Company;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\RequestE3Info;
use Firebed\AadeMyData\Models\ContinuationToken;
use GuzzleHttp\Handler\MockHandler;

/**
 * Ε3 overview from myDATA (E7). Pulls AADE's `RequestE3Info` for a window —
 * the Ε3 classification figures AADE has aggregated for our ΑΦΜ — and rolls
 * them up per (classification type, category) into an E3Report.
 *
 * READ-only (a GET); mirrors the reconciler's transport: '' empty-window
 * TypeError guard, continuationToken pagination, optional MockHandler test
 * seam. Tenant credentials via FirebedCredentials (throws for non-GR /
 * mode-off / missing creds — callers surface it).
 */
class E3Reporter
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    public function report(?Carbon $from = null, ?Carbon $to = null): E3Report
    {
        return $this->reportWithMonthly($from, $to)[0];
    }

    /**
     * report() + the same figures split by the entries' IssueDate month, for the
     * «Φορολογικά» month-by-month table. A separate return value (not a field on
     * E3Report) so the console's cached E3Report objects keep their shape.
     *
     * @return array{0: E3Report, 1: array<int, list<array{type: string, category: ?string, value: float}>>}
     */
    public function reportWithMonthly(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfQuarter();
        $to ??= now()->endOfQuarter();

        FirebedCredentials::init($this->tenant, $this->mockHandler);

        $fromStr = $from->format('d/m/Y');
        $toStr = $to->format('d/m/Y');

        /** @var array<string, array{type: string, category: ?string, value: float, count: int}> $byKey */
        $byKey = [];
        /** @var array<int, array<string, array{type: string, category: ?string, value: float}>> $byMonth */
        $byMonth = [];
        $docCount = 0;
        $total = 0.0;

        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestE3Info;
            $response = $action->handle($fromStr, $toStr, null, false, $nextPartitionKey, $nextRowKey);

            // Empty windows: AADE returns an empty/absent <E3Info>; firebed
            // stores it as scalar/null and the typed getter would TypeError.
            // Read raw + is_iterable, exactly like the reconcilers.
            $items = $response->get('E3Info');
            if (is_iterable($items)) {
                foreach ($items as $item) {
                    $type = trim((string) ($item->getClassType() ?? ''));
                    if ($type === '') {
                        continue;
                    }
                    $category = $item->getClassCategory();
                    $value = (float) ($item->getClassValue() ?? 0);

                    $key = $type.'|'.($category ?? '');
                    if (! isset($byKey[$key])) {
                        $byKey[$key] = ['type' => $type, 'category' => $category, 'value' => 0.0, 'count' => 0];
                    }
                    $byKey[$key]['value'] += $value;
                    $byKey[$key]['count']++;

                    // An undated entry (never seen) lands in the window's last month
                    // rather than vanishing from the month split.
                    $issued = (string) ($item->getIssueDate() ?? '');
                    $month = preg_match('/^\d{4}-(\d{2})/', $issued, $m) ? (int) $m[1] : (int) $to->month;
                    $byMonth[$month][$key] ??= ['type' => $type, 'category' => $category, 'value' => 0.0];
                    $byMonth[$month][$key]['value'] += $value;

                    $docCount++;
                    $total += $value;
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        $rows = [];
        foreach ($byKey as $agg) {
            $rows[] = new E3ReportRow(
                classType: $agg['type'],
                classCategory: $agg['category'],
                value: round($agg['value'], 2),
                count: $agg['count'],
            );
        }

        // Stable, operator-friendly order: by type then category.
        usort($rows, fn (E3ReportRow $a, E3ReportRow $b) => [$a->classType, $a->classCategory ?? '']
            <=> [$b->classType, $b->classCategory ?? '']);

        ksort($byMonth);
        $monthly = array_map(fn (array $rows) => array_values(array_map(
            fn (array $r) => ['type' => $r['type'], 'category' => $r['category'], 'value' => round($r['value'], 2)],
            $rows,
        )), $byMonth);

        return [new E3Report(
            from: $fromStr,
            to: $toStr,
            rows: $rows,
            docCount: $docCount,
            total: round($total, 2),
        ), $monthly];
    }
}
