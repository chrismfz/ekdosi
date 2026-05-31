<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Supplier;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Models\ContinuationToken;
use Firebed\AadeMyData\Models\Issuer;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;

/**
 * Imports "αδέσποτα" expense docs (E4 write-path): given a date window (and
 * optionally a single target MARK), pulls the FULL myDATA `RequestDocs`
 * documents OTHERS filed against us and records each as a local `Expense`
 * (+ `expense_lines`), linking/creating the `Supplier` by issuer AFM and
 * writing an `expense_marks` audit row.
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
     * Import every doc in the window (or just `$onlyMark` when given) that has
     * no local expense yet. Returns a per-run summary.
     */
    public function import(Carbon $from, Carbon $to, ?string $onlyMark = null): ExpenseImportResult
    {
        FirebedCredentials::init($this->tenant, $this->mockHandler);

        $docs = $this->fetchFullDocs($from->format('d/m/Y'), $to->format('d/m/Y'));

        $created = 0;
        $skipped = 0;
        $suppliersCreated = 0;
        $createdMarks = [];
        $skippedMarks = [];
        $notFoundMarks = [];

        // When importing a single MARK, surface "not found in window".
        if ($onlyMark !== null && ! isset($docs[$onlyMark])) {
            $notFoundMarks[] = $onlyMark;
        }

        foreach ($docs as $mark => $doc) {
            if ($onlyMark !== null && $mark !== $onlyMark) {
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

            DB::transaction(function () use ($doc, $mark, &$supplierWasCreated): void {
                $issuer = $doc->getIssuer();
                $supplier = $this->resolveSupplier($issuer, $supplierWasCreated);

                $header = $doc->getInvoiceHeader();
                $summary = $doc->getInvoiceSummary();

                /** @var Expense $expense */
                $expense = Expense::create([
                    'company_id' => $this->tenant->getKey(),
                    'supplier_id' => $supplier?->id,
                    'mydata_mark' => $mark,
                    'uid' => $doc->getUid(),
                    'authentication_code' => $doc->getAuthenticationCode(),
                    'invoice_type' => $header?->getInvoiceType()?->value,
                    'series' => $header?->getSeries(),
                    'aa' => $header?->getAa(),
                    'issue_date' => $header?->getIssueDate(),
                    'currency' => $header?->getCurrency() ?: 'EUR',
                    'supplier_afm' => $issuer instanceof Issuer ? $issuer->getVatNumber() : null,
                    'supplier_name' => $issuer instanceof Issuer ? $issuer->getName() : null,
                    'net_total' => $this->toDecimal($summary?->getTotalNetValue()),
                    'vat_total' => $this->toDecimal($summary?->getTotalVatAmount()),
                    'gross_total' => $this->toDecimal($summary?->getTotalGrossValue()),
                    // A cancelled doc would be folded by the reconciler; on
                    // import we record the live state. AADE only returns active
                    // docs in invoicesDoc, so default VALID.
                    'mydata_state' => 'VALID',
                    'source' => 'sync',
                ]);

                $this->importLines($expense, $doc);

                $expense->marks()->create([
                    'company_id' => $this->tenant->getKey(),
                    'mark' => $mark,
                    'mydata_action' => 'RequestDocs',
                    'response' => $doc->toXml(),
                ]);
            });

            $created++;
            $createdMarks[] = $mark;
            if ($supplierWasCreated) {
                $suppliersCreated++;
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
     * Fetch the full firebed Invoice objects for the window, keyed by MARK
     * (last write wins — a MARK is unique per AADE doc).
     *
     * @return array<string, \Firebed\AadeMyData\Models\Invoice>
     */
    private function fetchFullDocs(string $dateFrom, string $dateTo): array
    {
        $byMark = [];
        $nextPartitionKey = null;
        $nextRowKey = null;

        do {
            $action = new RequestDocs;
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

            $token = $response->get('continuationToken');
            $token = $token instanceof ContinuationToken ? $token : null;
            $nextPartitionKey = $token?->getNextPartitionKey();
            $nextRowKey = $token?->getNextRowKey();
        } while ($token !== null && (! empty($nextPartitionKey) || ! empty($nextRowKey)));

        return $byMark;
    }

    /**
     * Find the issuer's supplier by (company_id, afm); create a minimal
     * `source=sync` one if missing. GSIS enrichment is the supplier-sync
     * action's job — here we just take the doc name (if any). Sets
     * $created=true when a new row was inserted.
     */
    private function resolveSupplier(?Issuer $issuer, bool &$created): ?Supplier
    {
        if (! $issuer instanceof Issuer) {
            return null;
        }

        $afm = trim((string) ($issuer->getVatNumber() ?? ''));
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

        $country = strtoupper(trim((string) ($issuer->getCountry() ?? '')));
        $name = trim((string) ($issuer->getName() ?? ''));

        $created = true;

        return Supplier::create([
            'company_id' => $this->tenant->getKey(),
            'afm' => $afm,
            'name' => $name !== '' ? $name : null,
            'country' => $country !== '' ? substr($country, 0, 2) : 'GR',
            'source' => 'sync',
            'is_active' => true,
        ]);
    }

    private function importLines(Expense $expense, \Firebed\AadeMyData\Models\Invoice $doc): void
    {
        $details = $doc->getInvoiceDetails();
        if (! is_array($details)) {
            return;
        }

        foreach ($details as $line) {
            // Per-line E3 expense classification, if the issuer sent one. A line
            // may carry several; we keep the dominant (first) one — same single
            // type+category shape the header `classify` action uses, so the
            // codes resolve through the same Codes::expenseClass*Label() tables.
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
                // → string value, e.g. E3_102 / category2_3).
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
        \Firebed\AadeMyData\Models\InvoiceDetails $line
    ): ?\Firebed\AadeMyData\Models\ExpensesClassification {
        $list = $line->getExpensesClassification();

        return is_array($list) && $list !== [] ? $list[0] : null;
    }

    /**
     * Best-available human label for an imported expense line. myDATA expense
     * documents seldom carry `itemDescr` (spec-restricted to tax-free / delivery
     * types), so fall back to the line comments; null when neither exists (the
     * raw-XML viewer is the source of truth for everything else).
     */
    private function lineDescription(\Firebed\AadeMyData\Models\InvoiceDetails $line): ?string
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
