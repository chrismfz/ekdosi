<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Models\Expense;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use Firebed\AadeMyData\Models\Issuer;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Collection;

/**
 * Phase 2 — LIVE myDATA EXPENSES reconciliation (Έξοδα phase, E3).
 *
 * The expense-side twin of SalesReconciler. Pulls every document OTHERS filed
 * against us in a date window (`RequestDocs` — our εισροές, following the
 * continuationToken pagination) and cross-checks it against our local
 * `expenses` for the same window. Output: a read-only worklist of agreements
 * + discrepancies (ExpenseReconciliationResult); the actionable bucket is
 * `missingLocally` (a supplier doc with no local expense → "καταχώριση").
 *
 * Structure is identical to SalesReconciler on purpose:
 *   - fetchAadeDocs(): network + firebed parsing + pagination + cancellation
 *     folding (the empty-window TypeError guard included).
 *   - diff(): a PURE function over fetched AADE summaries + local expenses.
 *
 * Difference from the sales side: the relevant party on an expense doc is the
 * ISSUER (the supplier), not the counterpart (that's us). We fold the issuer's
 * name/AFM into the generic AadeDocSummary `counterpart*` fields — "the other
 * party" from our point of view — so the shared row/result shapes are reused
 * as-is.
 *
 * Tenant credentials use firebed's STATIC state (same caveat as MyDataSubmitter
 * / SalesReconciler — fine for FPM / sequential workers). The optional
 * MockHandler is the test seam. Tenant scoping is explicit (`company_id`) — no
 * reliance on a global scope (see CLAUDE.md latent items).
 */
class ExpenseReconciler
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Run a full expenses reconciliation for the window. Dates default to the
     * last calendar month → today. Carbon in; the AADE-facing dd/MM/yyyy
     * formatting happens internally.
     */
    public function reconcile(?Carbon $from = null, ?Carbon $to = null): ExpenseReconciliationResult
    {
        $from ??= now()->subMonth()->startOfDay();
        $to ??= now()->endOfDay();

        $this->initFirebed();

        $aadeDocs = $this->fetchAadeDocs(
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        );

        $local = $this->loadLocalExpenses($from, $to);

        return $this->diff(
            $aadeDocs,
            $local,
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        );
    }

    /**
     * Pull all docs filed against us in [dateFrom, dateTo] (dd/MM/yyyy),
     * following the continuationToken until AADE stops paginating. Returns a
     * list of AadeDocSummary with `cancelled` folded in from both the inline
     * <cancelledByMark> and the response's <cancelledInvoicesDoc> list, and
     * the ISSUER's identity in the counterpart* fields.
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
            $action = new RequestDocs;

            // '' mark + dd/MM/yyyy required by firebed's GET contract; the
            // last two args drive continuationToken pagination.
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

            // Empty windows: AADE returns an empty/absent <invoicesDoc>, which
            // firebed stores as a scalar/null — the typed getter would
            // TypeError. Read raw + is_iterable, exactly like SalesReconciler.
            $invoicesDoc = $response->get('invoicesDoc');
            if (is_iterable($invoicesDoc)) {
                foreach ($invoicesDoc as $doc) {
                    $mark = (string) $doc->getMark();
                    if ($mark === '') {
                        continue;
                    }

                    $header = $doc->getInvoiceHeader();
                    $summary = $doc->getInvoiceSummary();
                    $issuer = $doc->getIssuer();
                    $cancelledByMark = $doc->getCancelledByMark();

                    $byMark[$mark] = new AadeDocSummary(
                        mark: $mark,
                        uid: $doc->getUid(),
                        cancelled: $cancelledByMark !== null && $cancelledByMark !== '',
                        cancelledByMark: $cancelledByMark,
                        series: $header?->getSeries(),
                        aa: $header?->getAa(),
                        issueDate: $header?->getIssueDate(),
                        // The "other party" on an expense doc is the issuer
                        // (supplier), not the <counterpart> (that's us).
                        counterpartName: $issuer instanceof Issuer ? $issuer->getName() : null,
                        counterpartVat: $issuer instanceof Issuer ? $issuer->getVatNumber() : null,
                        // getTotalGrossValue() is parsed from XML as a STRING
                        // (firebed declares no cast); make the float explicit.
                        gross: $this->toFloat($summary?->getTotalGrossValue()),
                        net: $this->toFloat($summary?->getTotalNetValue()),
                        // §8.1 type — needed for the content compare (MYD-017).
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
                        // summary carries it as cancelledByMark — the sync persists it (MYD-014).
                        $cancelledMarks[$m] = (string) $cancelled->getCancellationMark();
                    }
                }
            }

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();

            // OR (not AND) the keys: if AADE ever returns one without the
            // other we still fetch the next page instead of dropping it.
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        // Fold the standalone cancellation list into the summaries.
        foreach ($cancelledMarks as $mark => $cancellationMark) {
            if (isset($byMark[$mark]) && ! $byMark[$mark]->cancelled) {
                $existing = $byMark[$mark];
                $byMark[$mark] = new AadeDocSummary(
                    mark: $existing->mark,
                    uid: $existing->uid,
                    cancelled: true,
                    cancelledByMark: $cancellationMark !== '' ? $cancellationMark : $existing->cancelledByMark,
                    series: $existing->series,
                    aa: $existing->aa,
                    issueDate: $existing->issueDate,
                    counterpartName: $existing->counterpartName,
                    counterpartVat: $existing->counterpartVat,
                    gross: $existing->gross,
                    net: $existing->net,
                    invoiceType: $existing->invoiceType,
                );
            }
        }

        return array_values($byMark);
    }

    /**
     * DIAGNOSTIC: return the RAW RequestDocs response XML, one string per
     * page, for a window. Read-only — eyeball the wire shape vs. what the
     * parser assumes. Not used by the normal reconcile path.
     *
     * @return list<string>
     */
    public function rawDocs(Carbon $from, Carbon $to): array
    {
        $this->initFirebed();

        $dateFrom = $from->format('d/m/Y');
        $dateTo = $to->format('d/m/Y');

        $pages = [];
        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestDocs;
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
     * Pure diff: AADE expense summaries vs. local expenses, both already
     * scoped to the same window. No DB, no network — the unit-tested core.
     *
     * @param  list<AadeDocSummary>  $aadeDocs
     * @param  Collection<int, Expense>  $localExpenses  expenses WITH a mydata_mark
     */
    public function diff(array $aadeDocs, Collection $localExpenses, string $from, string $to): ExpenseReconciliationResult
    {
        /** @var array<string, AadeDocSummary> $aadeByMark */
        $aadeByMark = [];
        foreach ($aadeDocs as $doc) {
            $aadeByMark[$doc->mark] = $doc;
        }

        $withMark = $localExpenses->filter(fn (Expense $e) => filled($e->mydata_mark));
        $grouped = $withMark->groupBy(fn (Expense $e) => (string) $e->mydata_mark);

        $matched = [];
        $stateMismatch = [];
        $contentMismatch = [];
        $contentIncomplete = [];
        $missingAtAade = [];
        $missingLocally = [];
        $duplicateLocal = [];

        // A MARK is unique per AADE doc — two local expenses sharing one is a
        // data-integrity fault the console exists to surface. Without this the
        // keyBy below would silently collapse them.
        foreach ($grouped as $group) {
            if ($group->count() > 1) {
                foreach ($group as $expense) {
                    $duplicateLocal[] = $this->rowFromLocal(
                        $expense,
                        aadeState: null,
                        problem: 'Διπλό ΜΑΡΚ: '.$group->count().' τοπικά έξοδα μοιράζονται αυτό το ΜΑΡΚ.',
                    );
                }
            }
        }

        $localByMark = $grouped->map(fn (Collection $g) => $g->first());

        foreach ($localByMark as $mark => $expense) {
            $aade = $aadeByMark[$mark] ?? null;

            if ($aade === null) {
                $missingAtAade[] = $this->rowFromLocal(
                    $expense,
                    aadeState: null,
                    problem: 'Καταχωρημένο τοπικά ως έξοδο με ΜΑΡΚ, αλλά το AADE δεν επιστρέφει αυτό το ΜΑΡΚ.',
                );

                continue;
            }

            $localCancelled = $expense->mydata_state === 'CANCELLED';

            if ($localCancelled === $aade->cancelled) {
                // MYD-017: MARK + state agree, but compare the content too. A value
                // CONFLICT → contentMismatch; a field AADE has but we LACK →
                // contentIncomplete (unverified); else matched.
                $aadeState = $aade->cancelled ? 'CANCELLED' : 'VALID';
                $cmp = ReconciliationContentComparator::compare($this->snapshotFrom($expense), $aade);
                if ($cmp->hasConflicts()) {
                    $contentMismatch[] = $this->rowFromLocal(
                        $expense,
                        aadeState: $aadeState,
                        problem: 'Διαφορές με ΑΑΔΕ (ίδιο ΜΑΡΚ & κατάσταση): '.implode(' · ', $cmp->conflicts),
                    );
                } elseif ($cmp->hasIncompletes()) {
                    $contentIncomplete[] = $this->rowFromLocal(
                        $expense,
                        aadeState: $aadeState,
                        problem: 'Ελλιπή τοπικά στοιχεία έναντι ΑΑΔΕ: '.implode(' · ', $cmp->incompletes),
                    );
                } else {
                    $matched[] = $this->rowFromLocal($expense, aadeState: $aadeState);
                }

                continue;
            }

            $problem = $aade->cancelled
                ? 'Ακυρωμένο στο AADE — τοπικά εμφανίζεται ως ενεργό. Χρειάζεται συγχρονισμός.'
                : 'Τοπικά ακυρωμένο — στο AADE εμφανίζεται ακόμη ως έγκυρο.';

            $stateMismatch[] = $this->rowFromLocal(
                $expense,
                aadeState: $aade->cancelled ? 'CANCELLED' : 'VALID',
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
                problem: 'Υπάρχει στο AADE (έξοδο που μας υπέβαλε προμηθευτής) αλλά δεν βρέθηκε τοπικά — απαιτείται καταχώριση.',
            );
        }

        return new ExpenseReconciliationResult(
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
        );
    }

    private function toFloat(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Flatten a local expense to the fields the content comparator checks.
     * Counterpart AFM is the supplier's, type is the recorded §8.1 `invoice_type`.
     */
    private function snapshotFrom(Expense $expense): LocalDocSnapshot
    {
        return new LocalDocSnapshot(
            // Expenses are imported straight from the AADE summary, so gross_total
            // already IS <totalGrossValue> (no [208] re-derivation needed here).
            gross: $expense->gross_total !== null ? (float) $expense->gross_total : null,
            net: $expense->net_total !== null ? (float) $expense->net_total : null,
            series: $expense->series,
            aa: $expense->aa,
            issueDate: $expense->issue_date?->format('Y-m-d'),
            counterpartVat: $expense->supplier_afm ?? $expense->supplier?->afm,
            invoiceType: $expense->invoice_type,
        );
    }

    private function rowFromLocal(
        Expense $expense,
        ?string $aadeState,
        ?string $cancelledByMark = null,
        ?string $problem = null,
    ): ReconciliationRow {
        return new ReconciliationRow(
            mark: (string) $expense->mydata_mark,
            uid: $expense->uid,
            expenseId: $expense->id,
            invcode: trim(($expense->series ?? '').' '.($expense->aa ?? '')) ?: null,
            issuedAt: $expense->issue_date?->format('d/m/Y'),
            counterpartName: $expense->supplier?->name ?? $expense->supplier_name,
            counterpartVat: $expense->supplier_afm ?? $expense->supplier?->afm,
            gross: $expense->gross_total !== null ? (float) $expense->gross_total : null,
            localState: $expense->mydata_state,
            aadeState: $aadeState,
            cancelledByMark: $cancelledByMark,
            problem: $problem,
        );
    }

    /**
     * Local expenses we believe are at AADE (have a mydata_mark),
     * tenant-scoped, issued within the window. The supplier relation is
     * eager-loaded for the worklist labels.
     *
     * @return Collection<int, Expense>
     */
    private function loadLocalExpenses(Carbon $from, Carbon $to): Collection
    {
        return Expense::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereNotNull('mydata_mark')
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->with('supplier')
            ->get();
    }

    private function initFirebed(): void
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);
    }
}
