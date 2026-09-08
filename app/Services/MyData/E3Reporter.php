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
        $from ??= now()->startOfQuarter();
        $to ??= now()->endOfQuarter();

        FirebedCredentials::init($this->tenant, $this->mockHandler);

        $fromStr = $from->format('d/m/Y');
        $toStr = $to->format('d/m/Y');

        /** @var array<string, array{type: string, category: ?string, value: float, count: int}> $byKey */
        $byKey = [];
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

        return new E3Report(
            from: $fromStr,
            to: $toStr,
            rows: $rows,
            docCount: $docCount,
            total: round($total, 2),
        );
    }
}
