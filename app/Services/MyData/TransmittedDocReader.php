<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Support\MyData\MarkDetail;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use GuzzleHttp\Handler\MockHandler;

/**
 * Fetches the FULL firebed documents the tenant has transmitted to AADE
 * (RequestTransmittedDocs) in a date window — header + counterpart +
 * the per-line <invoiceDetails> + summary.
 *
 * This is the line-level companion to App\Services\MyData\SalesReconciler:
 * the reconciler flattens each doc to an AadeDocSummary (header + totals
 * only) because matching against local invoices needs nothing more.
 * Rendering ONE orphan's full document (or, later, importing it) needs the
 * lines, so we keep the full firebed Invoice here — same pagination,
 * empty-window guard and cancellation-folding as the reconciler.
 *
 * Read-only. Tenant credentials via firebed's static state
 * (FirebedCredentials::init) — the optional MockHandler is the test seam,
 * mirroring SalesReconciler / ExpenseImporter.
 */
class TransmittedDocReader
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Resolve ONE document by MARK within the window and return the
     * Livewire-safe detail array (App\Support\MyData\MarkDetail shape),
     * or null when the window holds no such MARK. The cancellation state
     * is folded from both the inline <cancelledByMark> and the standalone
     * <cancelledInvoicesDoc> list.
     *
     * @return array<string, mixed>|null
     */
    public function fetchDetailByMark(string $mark, Carbon $from, Carbon $to): ?array
    {
        [$byMark, $cancelledMarks] = $this->fetch($from, $to);

        $doc = $byMark[$mark] ?? null;
        if ($doc === null) {
            return null;
        }

        $inline = $doc->getCancelledByMark();
        $cancelled = ($inline !== null && $inline !== '') || isset($cancelledMarks[$mark]);

        // The MARK of the cancellation ACT, when AADE gave us one — inline on the
        // doc, else from the standalone <cancelledInvoicesDoc> entry. It is the
        // evidence a state sync must persist, so it must survive this far
        // (MYD-023); an empty string means «cancelled, but AADE named no MARK».
        $cancelledByMark = ($inline !== null && $inline !== '')
            ? $inline
            : (($cancelledMarks[$mark] ?? '') ?: null);

        return MarkDetail::fromAadeDoc($doc, $cancelled, $this->tenant->afm, $cancelledByMark);
    }

    /**
     * Pull every transmitted doc in the window, keyed by MARK, plus the set
     * of MARKs AADE lists as cancelled in <cancelledInvoicesDoc>.
     *
     * The cancelled map is MARK => the cancellation MARK ('' when AADE listed the
     * document as cancelled without naming one), so a caller can persist WHICH
     * cancellation produced the state — not merely that one happened (MYD-023).
     *
     * @return array{0: array<string, AadeInvoice>, 1: array<string, string>}
     */
    private function fetch(Carbon $from, Carbon $to): array
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        $dateFrom = $from->format('d/m/Y');
        $dateTo = $to->format('d/m/Y');

        /** @var array<string, AadeInvoice> $byMark */
        $byMark = [];
        /** @var array<string, string> $cancelledMarks */
        $cancelledMarks = [];

        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestTransmittedDocs;

            // dd/MM/yyyy + a non-null '' mark are required by firebed's
            // MyDataGetRequest::handle (see SalesReconciler for the gotcha).
            $response = $action->handle(
                '',
                $dateFrom,
                $dateTo,
                null,
                null,
                null,
                null,
                $nextPartitionKey,
                $nextRowKey,
            );

            // Empty window → firebed stores invoicesDoc as a scalar and the
            // typed getter TypeErrors; read raw + is_iterable guard.
            $invoicesDoc = $response->get('invoicesDoc');
            if (is_iterable($invoicesDoc)) {
                foreach ($invoicesDoc as $doc) {
                    $mark = (string) $doc->getMark();
                    if ($mark !== '') {
                        $byMark[$mark] = $doc;
                    }
                }
            }

            $cancelledDoc = $response->get('cancelledInvoicesDoc');
            if (is_iterable($cancelledDoc)) {
                foreach ($cancelledDoc as $cancelled) {
                    $m = (string) $cancelled->getInvoiceMark();
                    if ($m !== '') {
                        $cancelledMarks[$m] = (string) $cancelled->getCancellationMark();
                    }
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        return [$byMark, $cancelledMarks];
    }
}
