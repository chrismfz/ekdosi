<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\MyDataGetRequest;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use Firebed\AadeMyData\Models\ExpensesClassification;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\Party;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;

/**
 * Imports myDATA documents into local `expenses` (+ `expense_lines` + an
 * `expense_marks` audit row). Two directions, sharing the same persist machinery:
 *
 *   - import()              (E4): "αδέσποτα" — the FULL `RequestDocs` documents
 *                                 OTHERS filed against us. Supplier = the
 *                                 issuer; source='sync'.
 *   - importSelfDeclared()  (E8): NON-income documents WE declared, from
 *                                 `RequestTransmittedDocs` — αποδείξεις (13.x),
 *                                 ενδοκοινοτικά/VIES/ΕΦΚΑ (14.x), μισθοδοσία/
 *                                 πάγια/τακτοποιήσεις (17.x). Real sales (1/2/…)
 *                                 are skipped. Supplier = the counterpart (when
 *                                 present, e.g. a 13.1 retail receipt has none);
 *                                 source='self_declared' + a `category` bucket
 *                                 so accounting entries don't read as invoices.
 *
 * Why a separate fetch from ExpenseReconciler: the reconciler flattens each
 * doc to an AadeDocSummary (header + totals only). Importing needs the per-line
 * `<invoiceDetails>`, so we parse the full firebed Invoice here. Same window,
 * same pagination/empty-window handling.
 *
 * Operator-gated (the caller decides when to run) and legally significant, so:
 *   - IDEMPOTENT: an expense whose MARK already exists locally (even
 *     soft-deleted — operator removed it on purpose) is skipped, never
 *     duplicated or resurrected. The (company_id, mydata_mark) unique index
 *     is the DB-level backstop.
 *   - each expense + its lines + supplier link + audit mark are written in one
 *     transaction.
 *
 * Tenant safety: scoped by `company_id` on every query/create — no reliance on
 * a global scope (CLAUDE.md latent item). Mirrors the WHMCS CLI paths.
 */
class ExpenseImporter
{
    public function __construct(
        private readonly Company $tenant,
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Import every supplier doc in the window (or just `$onlyMark` when given)
     * that has no local expense yet. Returns a per-run summary.
     */
    public function import(Carbon $from, Carbon $to, ?string $onlyMark = null): ExpenseImportResult
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        [$docs, $cancelledMarks] = $this->fetchFullDocs(new RequestDocs, $from->format('d/m/Y'), $to->format('d/m/Y'));

        return $this->persistDocs($docs, $cancelledMarks, mode: 'sync', onlyMarks: self::markSet($onlyMark));
    }

    /**
     * Import a SPECIFIC set of supplier-doc MARKs (the «Άντληση από myDATA» picker
     * on the Έξοδα list: fetch once, then the operator checks which αδέσποτα to
     * keep). One AADE fetch for the window, persist only the chosen MARKs.
     * Idempotent — already-present MARKs are skipped.
     *
     * @param  list<string>  $marks
     */
    public function importMarks(Carbon $from, Carbon $to, array $marks): ExpenseImportResult
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        [$docs, $cancelledMarks] = $this->fetchFullDocs(new RequestDocs, $from->format('d/m/Y'), $to->format('d/m/Y'));

        $set = [];
        foreach ($marks as $m) {
            $set[$m] = true;
        }

        return $this->persistDocs($docs, $cancelledMarks, mode: 'sync', onlyMarks: $set ?: null);
    }

    /** @return array<string, true>|null */
    private static function markSet(?string $mark): ?array
    {
        return $mark === null ? null : [$mark => true];
    }

    /**
     * Import the NON-income documents we declared ourselves
     * (RequestTransmittedDocs) — see the class docblock. Real sales types are
     * filtered out so this never duplicates the income side.
     */
    public function importSelfDeclared(Carbon $from, Carbon $to, ?string $onlyMark = null): ExpenseImportResult
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        [$all, $cancelledMarks] = $this->fetchFullDocs(new RequestTransmittedDocs, $from->format('d/m/Y'), $to->format('d/m/Y'));

        // Keep only what we file as an EXPENSE/other (13/14/17…); drop our sales.
        $docs = array_filter(
            $all,
            fn ($doc): bool => Codes::transmittedDocBucket($doc->getInvoiceHeader()?->getInvoiceType()?->value) !== 'income',
        );

        return $this->persistDocs($docs, $cancelledMarks, mode: 'self_declared', onlyMarks: self::markSet($onlyMark));
    }

    /**
     * Shared persist loop for both directions. `$mode` selects the provenance
     * (source + which Party is the "supplier" + the category bucket + the audit
     * action label). The sync path is byte-identical to the original.
     *
     * @param  array<string, Invoice>  $docs
     * @param  array<string, true>  $cancelledMarks  MARKs AADE folds as cancelled
     * @param  array<string, true>|null  $onlyMarks  restrict to this MARK set (null = all)
     */
    private function persistDocs(array $docs, array $cancelledMarks, string $mode, ?array $onlyMarks): ExpenseImportResult
    {
        $created = 0;
        $skipped = 0;
        $suppliersCreated = 0;
        $createdMarks = [];
        $skippedMarks = [];
        $notFoundMarks = [];

        // When importing a specific set, surface any MARK not present in the
        // window. Cast to string — PHP coerces numeric-string array keys to int.
        if ($onlyMarks !== null) {
            foreach (array_keys($onlyMarks) as $wanted) {
                if (! isset($docs[$wanted])) {
                    $notFoundMarks[] = (string) $wanted;
                }
            }
        }

        foreach ($docs as $mark => $doc) {
            if ($onlyMarks !== null && ! isset($onlyMarks[$mark])) {
                continue;
            }

            // Idempotency: skip a MARK we already hold (withTrashed → never
            // resurrect an operator-deleted expense).
            $exists = Expense::withTrashed()
                ->where('company_id', $this->tenant->getKey())
                ->where('mydata_mark', $mark)
                ->exists();

            if ($exists) {
                $skipped++;
                $skippedMarks[] = $mark;

                continue;
            }

            $supplierWasCreated = false;
            $createdExpense = null;

            // sync: the supplier IS the issuer. self_declared: WE are the issuer,
            // so the counterpart (when present) is the supplier. Resolve (and, for
            // a new GR supplier, GSIS-enrich) it BEFORE opening the write
            // transaction, so a registry SOAP lookup never holds the transaction
            // open. A supplier row created here but orphaned by a rolled-back
            // expense is benign — the next run links it.
            $party = $mode === 'self_declared' ? $doc->getCounterpart() : $doc->getIssuer();
            $supplier = $this->resolveSupplier($party, $supplierWasCreated);

            // Fold cancellation from both the inline <cancelledByMark> and the
            // standalone <cancelledInvoicesDoc> list (same as the reconciler), so
            // a doc we filed then cancelled isn't recorded as a live expense.
            $inlineCancel = (string) ($doc->getCancelledByMark() ?? '');
            $isCancelled = $inlineCancel !== '' || isset($cancelledMarks[$mark]);

            DB::transaction(function () use ($doc, $mark, $mode, $isCancelled, $party, $supplier, &$createdExpense): void {
                $header = $doc->getInvoiceHeader();
                $summary = $doc->getInvoiceSummary();
                $type = $header?->getInvoiceType()?->value;

                /** @var Expense $expense */
                $expense = Expense::create([
                    'company_id' => $this->tenant->getKey(),
                    'supplier_id' => $supplier?->id,
                    'mydata_mark' => $mark,
                    'uid' => $doc->getUid(),
                    'authentication_code' => $doc->getAuthenticationCode(),
                    'invoice_type' => $type,
                    'series' => $header?->getSeries(),
                    'aa' => $header?->getAa(),
                    'issue_date' => $header?->getIssueDate(),
                    'currency' => $header?->getCurrency() ?: 'EUR',
                    'supplier_afm' => $party instanceof Party ? $party->getVatNumber() : null,
                    'supplier_name' => $party instanceof Party ? $party->getName() : null,
                    'net_total' => $this->toDecimal($summary?->getTotalNetValue()),
                    'vat_total' => $this->toDecimal($summary?->getTotalVatAmount()),
                    'gross_total' => $this->toDecimal($summary?->getTotalGrossValue()),
                    // Doc-level links AADE ships in the XML — myDATA expense docs
                    // carry no line description, so these let the operator open the
                    // real παραστατικό (QR / issuer's e-invoice) to see what it is.
                    'qr_url' => $doc->getQrCodeUrl(),
                    'downloading_invoice_url' => $doc->getDownloadingInvoiceUrl(),
                    // Folded from <cancelledByMark> / <cancelledInvoicesDoc> so a
                    // cancelled doc imports as CANCELLED, not as a live expense.
                    'mydata_state' => $isCancelled ? 'CANCELLED' : 'VALID',
                    'source' => $mode,
                    // Coarse economic bucket — only for self-declared, so the UI
                    // splits πάγια/μισθοδοσία from real invoices. Null for sync.
                    'category' => $mode === 'self_declared'
                        ? Codes::selfDeclaredVatCategory($type)['key']
                        : null,
                ]);

                $this->importLines($expense, $doc);

                $expense->marks()->create([
                    'company_id' => $this->tenant->getKey(),
                    'mark' => $mark,
                    'mydata_action' => $mode === 'self_declared' ? 'RequestTransmittedDocs' : 'RequestDocs',
                    'response' => $doc->toXml(),
                ]);

                $createdExpense = $expense;
            });

            $created++;
            $createdMarks[] = $mark;
            if ($supplierWasCreated) {
                $suppliersCreated++;
            }

            // Auto-classification (#5): stamp the operator's «supplier → χαρακτηρισμός»
            // rule on a freshly-imported expense so recurring supplier docs land
            // pre-classified. No-op when no rule matches. Outside the create
            // transaction — a classification write must not roll back the import.
            if ($createdExpense !== null) {
                app(ExpenseClassifier::class)->classify($createdExpense);
            }
        }

        return new ExpenseImportResult(
            scannedDocs: count($docs),
            created: $created,
            skippedExisting: $skipped,
            suppliersCreated: $suppliersCreated,
            createdMarks: $createdMarks,
            skippedMarks: $skippedMarks,
            notFoundMarks: $notFoundMarks,
        );
    }

    /**
     * Fetch the full firebed Invoice objects for the window via the given GET
     * request (RequestDocs or RequestTransmittedDocs), keyed by MARK (last write
     * wins — a MARK is unique per AADE doc), plus the set of MARKs AADE lists as
     * cancelled in <cancelledInvoicesDoc>. Mirrors the reconciler's fold so a
     * cancelled doc is recorded as CANCELLED, not as a live expense.
     *
     * @return array{0: array<string, Invoice>, 1: array<string, true>}
     */
    private function fetchFullDocs(
        MyDataGetRequest $action,
        string $dateFrom,
        string $dateTo
    ): array {
        $byMark = [];
        $cancelledMarks = [];
        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $response = $action->handle('', $dateFrom, $dateTo, null, null, null, null, $nextPartitionKey, $nextRowKey);

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
                foreach ($cancelledDoc as $cancel) {
                    $m = (string) $cancel->getInvoiceMark();
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

        return [$byMark, $cancelledMarks];
    }

    /**
     * Find the party's supplier by (company_id, afm); create a `source=sync` one
     * if missing. Sets $created=true when a new row was inserted. Null when the
     * party is absent or has no AFM (e.g. a 13.1 retail receipt) — the expense
     * still imports, just without a supplier link.
     *
     * A GR issuer's name is forbidden in the myDATA doc ([219]/[220]) — only the
     * ΑΦΜ arrives — so a freshly-discovered GR supplier would land as a nameless
     * «παύλα». We resolve its identity from the GSIS registry (the same source
     * the Προμηθευτές «Άντληση από ΑΑΔΕ» and `suppliers:sync` use); best-effort,
     * so on any GSIS failure the ΑΦΜ-only supplier is still created. Foreign
     * docs carry name+address, so we skip the lookup for them.
     */
    private function resolveSupplier(?Party $party, bool &$created): ?Supplier
    {
        if (! $party instanceof Party) {
            return null;
        }

        $afm = trim((string) ($party->getVatNumber() ?? ''));
        if ($afm === '') {
            return null;
        }

        $supplier = Supplier::withTrashed()
            ->where('company_id', $this->tenant->getKey())
            ->where('afm', $afm)
            ->first();

        if ($supplier !== null) {
            return $supplier;
        }

        $country = strtoupper(trim((string) ($party->getCountry() ?? '')));
        $country = $country !== '' ? substr($country, 0, 2) : 'GR';
        $name = trim((string) ($party->getName() ?? ''));

        $attrs = [
            'company_id' => $this->tenant->getKey(),
            'afm' => $afm,
            'name' => $name !== '' ? $name : null,
            'country' => $country,
            'source' => 'sync',
            'is_active' => true,
        ];

        // GR docs carry no issuer name — fill επωνυμία/ΔΟΥ/διεύθυνση/δραστηριότητα
        // from GSIS so the supplier lands named, not a dash.
        if ($name === '' && $country === 'GR') {
            $gsis = (new SupplierGsisEnricher($this->tenant))->enrich($afm);
            if ($gsis !== null) {
                $attrs = array_merge($attrs, $gsis);
            }
        }

        $created = true;

        return Supplier::create($attrs);
    }

    private function importLines(Expense $expense, Invoice $doc): void
    {
        $details = $doc->getInvoiceDetails();
        if (! is_array($details)) {
            return;
        }

        foreach ($details as $line) {
            // Per-line E3 expense classification, if the issuer sent one. A line
            // may carry several; we keep the dominant (first) one — same single
            // type+category shape the header `classify` action uses, so the
            // codes resolve through the same Codes E3 label tables.
            $cls = $this->firstExpenseClassification($line);

            $expense->lines()->create([
                'company_id' => $this->tenant->getKey(),
                'line_number' => $line->getLineNumber(),
                'item_code' => $line->getItemCode(),
                // myDATA carries NO free-text on most expense docs: `itemDescr`
                // only exists for tax-free / delivery (9.3) types. Fall back to
                // the line comments when present so the row isn't blank; the raw
                // XML viewer (ExpenseInfolist) covers what's still not shown.
                'item_descr' => $this->lineDescription($line),
                'quantity' => $line->getQuantity(),
                'measurement_unit' => $line->getMeasurementUnit()?->value,
                'net_value' => $this->toDecimal($line->getNetValue()),
                // VERBATIM §8.2/§8.3 codes (firebed backed enums → int value).
                'vat_category' => $line->getVatCategory()?->value,
                'vat_exemption_category' => $line->getVatExemptionCategory()?->value,
                'vat_amount' => $this->toDecimal($line->getVatAmount()),
                // E3 classification codes stored verbatim (firebed backed enums
                // → string value, e.g. E3_102_001 / category2_3).
                'classification_type' => $cls?->getClassificationType()?->value,
                'classification_category' => $cls?->getClassificationCategory()?->value,
            ]);
        }
    }

    /**
     * The dominant per-line expense classification (the first, when present).
     * A line can carry several; we record one — mirroring the single
     * type+category the header `classify` action stores. Null when the issuer
     * sent no per-line classification (common — many docs classify only at the
     * recipient's discretion later).
     */
    private function firstExpenseClassification(
        InvoiceDetails $line
    ): ?ExpensesClassification {
        $list = $line->getExpensesClassification();

        return is_array($list) && $list !== [] ? $list[0] : null;
    }

    /**
     * Best-available human label for an imported expense line. myDATA expense
     * documents seldom carry `itemDescr` (spec-restricted to tax-free / delivery
     * types), so fall back to the line comments; null when neither exists (the
     * raw-XML viewer is the source of truth for everything else).
     */
    private function lineDescription(InvoiceDetails $line): ?string
    {
        $descr = trim((string) ($line->getItemDescr() ?? ''));
        if ($descr !== '') {
            return $descr;
        }

        $comments = trim((string) ($line->getLineComments() ?? ''));

        return $comments !== '' ? $comments : null;
    }

    private function toDecimal(?float $value): float
    {
        return $value === null ? 0.0 : $value;
    }
}
