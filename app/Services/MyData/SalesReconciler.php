<?php

namespace App\Services\MyData;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Phase 2 — LIVE myDATA sales reconciliation.
 *
 * Pulls every document the tenant has filed at AADE in a date window
 * (RequestTransmittedDocs, following the continuationToken pagination)
 * and cross-checks it against our local `invoices` for the same window.
 * The output is a read-only worklist of agreements + discrepancies
 * (App\Services\MyData\SalesReconciliationResult).
 *
 * This is the network-backed counterpart to the Phase-1
 * MyDataReconciliation page, which only cross-checks our two internal
 * columns and never calls AADE.
 *
 * Design split (mirrors CustomerLedgerBuilder / WhmcsInvoiceMapper):
 *   - fetchAadeDocs(): the network + firebed parsing + pagination.
 *   - diff(): a PURE function over already-fetched AADE summaries +
 *     local invoices. Unit-tested without any network.
 *
 * Tenant credentials use firebed's STATIC state (same caveat as
 * MyDataSubmitter — fine for FPM / sequential workers, a contention
 * point under Octane). The optional MockHandler is the test seam.
 */
class SalesReconciler
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Run a full reconciliation for the window. Dates default to the
     * last calendar month → today. Carbon in; the AADE-facing dd/MM/yyyy
     * formatting happens internally.
     */
    public function reconcile(?Carbon $from = null, ?Carbon $to = null): SalesReconciliationResult
    {
        $from ??= now()->subMonth()->startOfDay();
        $to ??= now()->endOfDay();

        $this->initFirebed();

        $aadeDocs = $this->fetchAadeDocs(
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        );

        $local = $this->loadLocalInvoices($from, $to);

        return $this->diff(
            $aadeDocs,
            $local,
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        );
    }

    /**
     * Pull all transmitted docs in [dateFrom, dateTo] (dd/MM/yyyy),
     * following the continuationToken until AADE stops paginating.
     * Returns a list of AadeDocSummary, with the `cancelled` flag
     * already folded in from both the inline <cancelledByMark> and the
     * response's <cancelledInvoicesDoc> list.
     *
     * @return list<AadeDocSummary>
     */
    public function fetchAadeDocs(string $dateFrom, string $dateTo): array
    {
        /** @var array<string, AadeDocSummary> $byMark */
        $byMark = [];
        /** @var array<string, string> $cancelledMarks  invoiceMark => cancellationMark */
        $cancelledMarks = [];

        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestTransmittedDocs;

            // dd/MM/yyyy date format and a NON-NULL '' mark are required
            // by firebed's MyDataGetRequest::handle (see MyDataSubmitter
            // testConnection for the gotcha notes).
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

            // AADE returns an EMPTY container element (<invoicesDoc/>)
            // when nothing matches the window. firebed's reader then
            // stores `invoicesDoc` as a scalar string — and the typed
            // getInvoices(): ?InvoicesDoc getter THROWS a TypeError on
            // that. Read the raw attribute via get() and guard with
            // is_iterable() so the common "empty window" response is safe.
            $invoicesDoc = $response->get('invoicesDoc');
            if (is_iterable($invoicesDoc)) {
                foreach ($invoicesDoc as $doc) {
                    $mark = (string) $doc->getMark();
                    if ($mark === '') {
                        continue;
                    }

                    $header = $doc->getInvoiceHeader();
                    $summary = $doc->getInvoiceSummary();
                    $counterpart = $doc->getCounterpart();
                    $cancelledByMark = $doc->getCancelledByMark();

                    $byMark[$mark] = new AadeDocSummary(
                        mark: $mark,
                        uid: $doc->getUid(),
                        cancelled: $cancelledByMark !== null && $cancelledByMark !== '',
                        cancelledByMark: $cancelledByMark,
                        series: $header?->getSeries(),
                        aa: $header?->getAa(),
                        issueDate: $header?->getIssueDate(),
                        counterpartName: $counterpart?->getName(),
                        counterpartVat: $counterpart?->getVatNumber(),
                        // Read the RAW attribute via get(), NOT the typed
                        // getTotal*Value(): ?float getters — those coerce the XML
                        // string on return and THROW a TypeError on a blank/
                        // non-numeric total, which would abort the whole fetch.
                        // toFloat() then maps blank/non-numeric → null (unverified).
                        gross: $this->toFloat($summary?->get('totalGrossValue')),
                        net: $this->toFloat($summary?->get('totalNetValue')),
                        invoiceType: $header?->getInvoiceType()?->value,
                    );
                }
            }

            $cancelledDoc = $response->get('cancelledInvoicesDoc');
            if (is_iterable($cancelledDoc)) {
                foreach ($cancelledDoc as $cancelled) {
                    $m = (string) $cancelled->getInvoiceMark();
                    if ($m !== '') {
                        // Keep the cancellation MARK (not just a flag) so the folded
                        // summary can carry it as cancelledByMark (MYD-014).
                        $cancelledMarks[$m] = (string) $cancelled->getCancellationMark();
                    }
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();

            // Continue while AADE handed back a continuation token with at
            // least one key. ORing the keys (vs ANDing) is the safe choice:
            // if AADE ever returns one key without the other we still
            // fetch the next page instead of silently dropping it.
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        // Fold the standalone cancellation list into the summaries: a
        // MARK listed in <cancelledInvoicesDoc> is cancelled even if its
        // invoice element didn't carry an inline <cancelledByMark>.
        foreach ($cancelledMarks as $mark => $cancellationMark) {
            if (isset($byMark[$mark]) && ! $byMark[$mark]->cancelled) {
                $byMark[$mark] = $byMark[$mark]->withCancellation($cancellationMark);
            }
        }

        return array_values($byMark);
    }

    /**
     * DIAGNOSTIC: return the RAW RequestTransmittedDocs response XML, one
     * string per page, for a window. Read-only. Use this to eyeball the
     * actual AADE wire shape against what the parser/mapper assumes — the
     * fastest way to debug a "buckets look wrong" surprise on the first
     * live sandbox run. Not used by the normal reconcile path.
     *
     * @return list<string>
     */
    public function rawTransmittedDocs(Carbon $from, Carbon $to): array
    {
        $this->initFirebed();

        $dateFrom = $from->format('d/m/Y');
        $dateTo = $to->format('d/m/Y');

        $pages = [];
        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestTransmittedDocs;
            $response = $action->handle('', $dateFrom, $dateTo, null, null, null, null, $nextPartitionKey, $nextRowKey);

            $pages[] = $action->getResponseXML() ?? '';

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        return $pages;
    }

    /**
     * Pure diff: AADE summaries vs. local invoices, both already scoped
     * to the same window. No DB, no network — the unit-tested core.
     *
     * @param  list<AadeDocSummary>  $aadeDocs
     * @param  Collection<int, Invoice>  $localInvoices  invoices WITH a mydata_mark
     */
    public function diff(array $aadeDocs, Collection $localInvoices, string $from, string $to): SalesReconciliationResult
    {
        /** @var array<string, AadeDocSummary> $aadeByMark */
        $aadeByMark = [];
        foreach ($aadeDocs as $doc) {
            $aadeByMark[$doc->mark] = $doc;
        }

        $withMark = $localInvoices->filter(fn (Invoice $i) => filled($i->mydata_mark));
        $grouped = $withMark->groupBy(fn (Invoice $i) => (string) $i->mydata_mark);

        $matched = [];
        $stateMismatch = [];
        $contentMismatch = [];
        $contentIncomplete = [];
        $missingAtAade = [];
        $missingLocally = [];
        $duplicateLocal = [];

        // A MARK is unique per AADE filing — two local invoices sharing
        // one is a data-integrity fault (bad ETL / double-write) that the
        // console exists to surface. Without this the keyBy below would
        // silently collapse them and hide the very problem we look for.
        foreach ($grouped as $group) {
            if ($group->count() > 1) {
                foreach ($group as $invoice) {
                    $duplicateLocal[] = $this->rowFromLocal(
                        $invoice,
                        aadeState: null,
                        problem: 'Διπλό ΜΑΡΚ: '.$group->count().' τοπικά παραστατικά μοιράζονται αυτό το ΜΑΡΚ.',
                    );
                }
            }
        }

        $localByMark = $grouped->map(fn (Collection $g) => $g->first());

        foreach ($localByMark as $mark => $invoice) {
            $aade = $aadeByMark[$mark] ?? null;

            if ($aade === null) {
                $missingAtAade[] = $this->rowFromLocal(
                    $invoice,
                    aadeState: null,
                    problem: 'Καταχωρημένο τοπικά ως υποβληθέν, αλλά το AADE δεν επιστρέφει αυτό το ΜΑΡΚ.',
                );

                continue;
            }

            $localCancelled = $invoice->mydata_state === 'CANCELLED';
            $aadeState = $aade->cancelled ? 'CANCELLED' : 'VALID';

            if ($localCancelled === $aade->cancelled) {
                // MYD-017: MARK + state agree, but that is NOT proof the content
                // matches. A value CONFLICT → contentMismatch (danger); a field AADE
                // carries but we LACK → contentIncomplete (unverified); else matched.
                $cmp = ReconciliationContentComparator::compare($this->snapshotFrom($invoice), $aade);
                if ($cmp->hasConflicts()) {
                    $contentMismatch[] = $this->rowFromLocal(
                        $invoice,
                        aadeState: $aadeState,
                        problem: 'Διαφορές με ΑΑΔΕ (ίδιο ΜΑΡΚ & κατάσταση): '.implode(' · ', $cmp->conflicts),
                    );
                } elseif ($cmp->hasIncompletes()) {
                    $contentIncomplete[] = $this->rowFromLocal(
                        $invoice,
                        aadeState: $aadeState,
                        problem: 'Ελλιπή τοπικά στοιχεία έναντι ΑΑΔΕ: '.implode(' · ', $cmp->incompletes),
                    );
                } else {
                    $matched[] = $this->rowFromLocal($invoice, aadeState: $aadeState);
                }

                continue;
            }

            $problem = $aade->cancelled
                ? 'Ακυρωμένο στο AADE — τοπικά εμφανίζεται ως ενεργό. Χρειάζεται συγχρονισμός.'
                : 'Τοπικά ακυρωμένο — στο AADE εμφανίζεται ακόμη ως έγκυρο. Η ακύρωση δεν έφτασε στο AADE.';

            $stateMismatch[] = $this->rowFromLocal(
                $invoice,
                aadeState: $aadeState,
                cancelledByMark: $aade->cancelledByMark,
                problem: $problem,
            );
        }

        foreach ($aadeByMark as $mark => $aade) {
            if ($localByMark->has($mark)) {
                continue;
            }

            $missingLocally[] = new ReconciliationRow(
                mark: $mark,
                uid: $aade->uid,
                invcode: $aade->series !== null ? trim(($aade->series ?? '').' '.($aade->aa ?? '')) : $aade->aa,
                issuedAt: $aade->issueDate,
                counterpartName: $aade->counterpartName,
                counterpartVat: $aade->counterpartVat,
                gross: $aade->gross,
                net: $aade->net,
                aadeState: $aade->cancelled ? 'CANCELLED' : 'VALID',
                cancelledByMark: $aade->cancelledByMark,
                problem: 'Υπάρχει στο AADE αλλά δεν βρέθηκε τοπικά (πιθανή υποβολή από άλλο σύστημα ή χαμένη εγγραφή).',
                invoiceType: $aade->invoiceType,
                invoiceTypeLabel: $aade->invoiceType !== null
                    ? (Codes::INVOICE_TYPES[$aade->invoiceType] ?? null)
                    : null,
            );
        }

        return new SalesReconciliationResult(
            from: $from,
            to: $to,
            aadeTotal: count($aadeByMark),
            localTotal: $withMark->count(),
            matched: $matched,
            stateMismatch: $stateMismatch,
            contentMismatch: $contentMismatch,
            contentIncomplete: $contentIncomplete,
            missingAtAade: $missingAtAade,
            missingLocally: $missingLocally,
            duplicateLocal: $duplicateLocal,
            sandbox: $this->tenant->mydata_mode_enum === MyDataMode::Sandbox,
        );
    }

    private function toFloat(mixed $value): ?float
    {
        // Blank (<totalNetValue/>) or non-numeric → null (UNVERIFIED), never 0.0.
        // A real zero-value document sends a numeric '0'/'0.00', which stays 0.0.
        // $value is the RAW attribute (string|float|null) — a float is already good.
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Flatten a local invoice to the fields the content comparator checks. Series
     * comes from the type code, ΑΑ from `code`, type from the denormalised
     * `mydata_type` cache, counterpart AFM from the FROZEN `vat_no` snapshot.
     */
    private function snapshotFrom(Invoice $invoice): LocalDocSnapshot
    {
        $filed = FiledInvoiceTotals::for($invoice);

        return new LocalDocSnapshot(
            // Money comes from FiledInvoiceTotals — the same roll-up the submitter
            // files (per-VAT-rate rounding + the [208] adjustment), NOT the ledger
            // columns, which round differently and never carry the adjustment.
            // A null means "not reconstructable" → the comparator reports it as
            // unverified instead of contradicting AADE with a number we never sent.
            gross: $filed->gross,
            net: $filed->net,
            series: $invoice->invoiceType?->code,
            aa: $invoice->code !== null ? (string) $invoice->code : null,
            issueDate: $invoice->issued_at?->format('Y-m-d'),
            // Fall back to the relations when the denormalised snapshot columns are
            // null: app-issued invoices carry mydata_type/vat_no, but ETL-imported
            // legacy invoices don't (the ETL snapshots the type onto invoice_types,
            // not each invoice). Without the fallback every legacy invoice — matched
            // by state against a real production MARK — would read as contentIncomplete
            // forever (permanent exit-2 on the scheduled reconcile). The relation value
            // is exactly what WOULD have been snapshotted, so it's authoritative, not a
            // guess; the fallback only fires when the cache is empty (no masking).
            counterpartVat: $invoice->vat_no ?: $invoice->customer?->afm,
            invoiceType: $invoice->mydata_type ?: $invoice->invoiceType?->mydata_type,
        );
    }

    private function rowFromLocal(
        Invoice $invoice,
        ?string $aadeState,
        ?string $cancelledByMark = null,
        ?string $problem = null,
    ): ReconciliationRow {
        return new ReconciliationRow(
            mark: (string) $invoice->mydata_mark,
            uid: null,
            invoiceId: $invoice->id,
            invcode: $invoice->invcode,
            issuedAt: $invoice->issued_at?->format('d/m/Y'),
            counterpartName: $invoice->customer?->name,
            // Same basis as the content compare — the FILED gross, nullable. NO
            // ledger-gross fallback: showing gross_total here while the problem says
            // the filed gross "δεν προσδιορίζεται" would put two different local
            // grosses on one row. A null renders as «—» (the honest state).
            gross: FiledInvoiceTotals::for($invoice)->gross,
            localState: $invoice->mydata_state,
            localStatus: $invoice->local_status,
            aadeState: $aadeState,
            cancelledByMark: $cancelledByMark,
            problem: $problem,
            legacyId: $invoice->legacy_id,
        );
    }

    /**
     * Local invoices that we believe are filed (have a mydata_mark),
     * tenant-scoped, issued within the window. The customer relation is
     * eager-loaded for the worklist labels.
     *
     * @return Collection<int, Invoice>
     */
    private function loadLocalInvoices(Carbon $from, Carbon $to): Collection
    {
        return Invoice::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereNotNull('mydata_mark')
            ->whereBetween('issued_at', [$from, $to])
            // lines → FiledInvoiceTotals (the filed net/gross roll-up); invoiceType →
            // series/type for the content compare (MYD-017).
            ->with('customer', 'invoiceType', 'lines')
            ->get();
    }

    private function initFirebed(): void
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);
    }
}
