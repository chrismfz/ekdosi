<?php

namespace App\Services\MyData;

use App\Models\Company;
use Carbon\CarbonInterface;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use GuzzleHttp\Handler\MockHandler;

/**
 * Computes the "Εικόνα από myDATA" VAT picture by SUMMING the actual documents
 * AADE holds for a period — the authoritative source, vs the local-books
 * VatPeriodReport:
 *   - output (εκροές): RequestTransmittedDocs (the sales WE filed)
 *   - input  (εισροές): RequestDocs (the expense docs filed AGAINST us)
 * Cancelled docs (inline <cancelledByMark> OR listed in <cancelledInvoicesDoc>)
 * are excluded from both sums.
 *
 * Both endpoints share firebed's GET transport (same handle signature + the
 * invoicesDoc / cancelledInvoicesDoc / continuationToken response shape), so a
 * single private summer drives both. Same '' empty-window TypeError guard +
 * pagination idiom as the reconcilers; optional MockHandler test seam.
 *
 * This is the heavy part (paginates ALL docs for the window), so it runs on a
 * scheduler into a cache (see MyDataVatPictureCache) — never live on a
 * dashboard load.
 */
class MyDataVatAggregator
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    public function forPeriod(CarbonInterface $from, CarbonInterface $to): MyDataVatPicture
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        $fromStr = $from->format('d/m/Y');
        $toStr = $to->format('d/m/Y');

        $output = $this->sumDocs(fn () => new RequestTransmittedDocs, $fromStr, $toStr);
        $input = $this->sumDocs(fn () => new RequestDocs, $fromStr, $toStr);

        return new MyDataVatPicture(
            outputNet: $output['net'],
            outputVat: $output['vat'],
            outputGross: $output['gross'],
            outputCount: $output['count'],
            inputNet: $input['net'],
            inputVat: $input['vat'],
            inputGross: $input['gross'],
            inputCount: $input['count'],
            fetchedAt: now()->toIso8601String(),
        );
    }

    /**
     * Paginate one GET endpoint over the window, summing net/vat/gross of the
     * NON-cancelled docs.
     *
     * @param  callable():(\Firebed\AadeMyData\Http\MyDataGetRequest)  $make
     * @return array{net: float, vat: float, gross: float, count: int}
     */
    private function sumDocs(callable $make, string $dateFrom, string $dateTo): array
    {
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;
        $count = 0;

        /** @var array<string, array{net: float, vat: float, gross: float}> $live */
        $live = [];
        /** @var array<string, true> $cancelledMarks */
        $cancelledMarks = [];

        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = $make();
            $response = $action->handle('', $dateFrom, $dateTo, null, null, null, null, $nextPartitionKey, $nextRowKey);

            $invoicesDoc = $response->get('invoicesDoc');
            if (is_iterable($invoicesDoc)) {
                foreach ($invoicesDoc as $doc) {
                    $mark = (string) $doc->getMark();
                    if ($mark === '') {
                        continue;
                    }
                    // Skip inline-cancelled; defer standalone-cancelled folding.
                    if (filled($doc->getCancelledByMark())) {
                        $cancelledMarks[$mark] = true;
                    }
                    $summary = $doc->getInvoiceSummary();
                    $live[$mark] = [
                        'net' => (float) ($summary?->getTotalNetValue() ?? 0),
                        'vat' => (float) ($summary?->getTotalVatAmount() ?? 0),
                        'gross' => (float) ($summary?->getTotalGrossValue() ?? 0),
                    ];
                }
            }

            $cancelledDoc = $response->get('cancelledInvoicesDoc');
            if (is_iterable($cancelledDoc)) {
                foreach ($cancelledDoc as $cancelled) {
                    $m = (string) $cancelled->getInvoiceMark();
                    if ($m !== '') {
                        $cancelledMarks[$m] = true;
                    }
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        foreach ($live as $mark => $sums) {
            if (isset($cancelledMarks[$mark])) {
                continue;
            }
            $net += $sums['net'];
            $vat += $sums['vat'];
            $gross += $sums['gross'];
            $count++;
        }

        return [
            'net' => round($net, 2),
            'vat' => round($vat, 2),
            'gross' => round($gross, 2),
            'count' => $count,
        ];
    }
}
