<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Support\MyData\Codes;
use Carbon\CarbonInterface;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use GuzzleHttp\Handler\MockHandler;

/**
 * Computes the "Εικόνα από myDATA" VAT picture by SUMMING the actual documents
 * AADE holds for a period — the authoritative source, vs the local-books
 * VatPeriodReport.
 *
 *   - output (έσοδα/εκροές): RequestTransmittedDocs **filtered to income types
 *     only** (1/2/5/6/7/8/11). RequestTransmittedDocs ALSO returns the docs we
 *     self-declared — μισθοδοσία (17.x), ενδοκοινοτικά (14.x), ΑΛΠ (13.x) —
 *     which are NOT sales; summing them all as Έσοδα overstated income (a
 *     payroll could read as a €5k sale). Those non-income docs are broken out
 *     into `breakdown` (κατηγορίες: Ενδοκοινοτικά, Μισθοδοσία, …) so they're
 *     visible without polluting Έσοδα / ΦΠΑ εκροών.
 *   - input  (έξοδα/εισροές): RequestDocs (the expense docs filed AGAINST us).
 *
 * Cancelled docs (inline <cancelledByMark> OR listed in <cancelledInvoicesDoc>)
 * are excluded everywhere.
 *
 * Heavy (paginates ALL docs for the window) → runs on a scheduler into a cache
 * (VatPictureCache), never live on a dashboard load. Optional MockHandler seam.
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

        // Output side: classify each transmitted doc by §8.1 type.
        $outNet = $outVat = $outGross = 0.0;
        $outCount = 0;
        /** @var array<string, array{label: string, net: float, vat: float, count: int}> $breakdown */
        $breakdown = [];

        foreach ($this->fetchLiveDocs(fn () => new RequestTransmittedDocs, $fromStr, $toStr) as $doc) {
            $type = $doc['type'];
            if ($type !== null && Codes::isIncomeInvoiceType($type)) {
                $outNet += $doc['net'];
                $outVat += $doc['vat'];
                $outGross += $doc['gross'];
                $outCount++;

                continue;
            }
            // Self-declared non-income (μισθοδοσία / ενδοκοινοτικά / ΑΛΠ / λοιπά).
            $cat = Codes::selfDeclaredVatCategory($type);
            $key = $cat['key'];
            $breakdown[$key] ??= ['label' => $cat['label'], 'net' => 0.0, 'vat' => 0.0, 'count' => 0];
            $breakdown[$key]['net'] += $doc['net'];
            $breakdown[$key]['vat'] += $doc['vat'];
            $breakdown[$key]['count']++;
        }
        foreach ($breakdown as &$b) {
            $b['net'] = round($b['net'], 2);
            $b['vat'] = round($b['vat'], 2);
        }
        unset($b);

        // Input side: every expense doc filed against us.
        $inNet = $inVat = $inGross = 0.0;
        $inCount = 0;
        foreach ($this->fetchLiveDocs(fn () => new RequestDocs, $fromStr, $toStr) as $doc) {
            $inNet += $doc['net'];
            $inVat += $doc['vat'];
            $inGross += $doc['gross'];
            $inCount++;
        }

        return new MyDataVatPicture(
            outputNet: round($outNet, 2),
            outputVat: round($outVat, 2),
            outputGross: round($outGross, 2),
            outputCount: $outCount,
            inputNet: round($inNet, 2),
            inputVat: round($inVat, 2),
            inputGross: round($inGross, 2),
            inputCount: $inCount,
            fetchedAt: now()->toIso8601String(),
            breakdown: $breakdown,
        );
    }

    /**
     * Paginate one GET endpoint over the window and return the NON-cancelled
     * docs as a list of per-doc sums + §8.1 invoice type.
     *
     * @param  callable():(\Firebed\AadeMyData\Http\MyDataGetRequest)  $make
     * @return list<array{net: float, vat: float, gross: float, type: ?string}>
     */
    private function fetchLiveDocs(callable $make, string $dateFrom, string $dateTo): array
    {
        /** @var array<string, array{net: float, vat: float, gross: float, type: ?string}> $live */
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
                    if (filled($doc->getCancelledByMark())) {
                        $cancelledMarks[$mark] = true;
                    }
                    $summary = $doc->getInvoiceSummary();
                    $live[$mark] = [
                        'net' => (float) ($summary?->getTotalNetValue() ?? 0),
                        'vat' => (float) ($summary?->getTotalVatAmount() ?? 0),
                        'gross' => (float) ($summary?->getTotalGrossValue() ?? 0),
                        'type' => $doc->getInvoiceHeader()?->getInvoiceType()?->value,
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

        $out = [];
        foreach ($live as $mark => $sums) {
            if (isset($cancelledMarks[$mark])) {
                continue;
            }
            $out[] = $sums;
        }

        return $out;
    }
}
