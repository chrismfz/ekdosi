<?php

namespace App\Services\EInvoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\InvoiceVatBreakdown;
use App\Support\Afm;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\CountryCode;
use Firebed\AadeMyData\Enums\CurrencyCode;
use Firebed\AadeMyData\Enums\FeesPercentCategory;
use Firebed\AadeMyData\Enums\OtherTaxesPercentCategory;
use Firebed\AadeMyData\Enums\StampCategory;
use Firebed\AadeMyData\Enums\TaxType;
use Firebed\AadeMyData\Enums\VatCategory as AadeVatCategory;
use Firebed\AadeMyData\Enums\VatExemption;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\InvoicesDoc;
use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use Firebed\AadeMyData\Models\TaxTotals;
use Firebed\AadeMyData\Xml\InvoicesDocWriter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Builds the canonical AADE invoice payload (firebed AadeInvoice → InvoicesDoc
 * XML) from our domain Invoice/InvoiceLine/Company. Factored out of
 * MyDataSubmitter (P0 of the e-invoice-provider work, see
 * docs/paroxos/implementation-plan.md §2.1) so the SAME payload can feed BOTH:
 *   - the direct myDATA submitter (MyDataSubmitter), and
 *   - a future provider submitter (GrProviderSubmitter), which wraps this same
 *     XML in a provider transport (and, for providers like InvoSign, appends a
 *     provider-specific extension block).
 *
 * Behaviour is IDENTICAL to the old MyDataSubmitter::buildAadeInvoice /
 * ::payloadToXml — this is a pure move, no semantic change. Golden/safety tests
 * (MyDataSubmitterSafetyTest, IssueInvoiceFlowTest) guard that.
 *
 * Per-tenant: takes the issuing Company (for AFM, the 0%-exemption lookup, etc.).
 */
class AadeInvoiceDocument
{
    public function __construct(private readonly Company $tenant) {}

    /** G4: memoised tenant VAT-exemption reason for 0% lines (§8.3). */
    private ?int $resolvedExemptionCategory = null;

    /**
     * Build the firebed AadeInvoice domain object from our Invoice.
     * Field mapping is the heart of the bridge between our schema
     * and AADE's payload shape. Keep this small and readable; any
     * special-case logic (e.g. intra-community zero-VAT rules) lives
     * in dedicated value objects, not inline here.
     */
    public function build(Invoice $invoice): AadeInvoice
    {
        // lines.product.productCategory feeds the per-line E3 income class (MYD-5).
        $invoice->loadMissing(['lines.product.productCategory', 'invoiceType', 'customer']);

        // Draft guard: code (ΑΑ) is allocated by InvoiceNumberer at
        // issue-time. A code of 0 means the InvoiceNumberer wasn't run
        // (draft invoice, test fixture, broken save path). Building an
        // AADE payload with <aa>0</aa> would either be rejected with
        // an opaque error OR — worse — accepted as a real filing for a
        // technically-valid invoice number 0. Fail fast.
        if (! $invoice->code || (int) $invoice->code < 1) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has no ΑΑ number (code=".($invoice->code ?? 'null').'). '.
                'Allocate via App\\Services\\InvoiceNumberer before submission.'
            );
        }

        $type = $invoice->invoiceType?->mydata_type
            ?? throw new RuntimeException(
                "Invoice {$invoice->invcode} cannot be submitted — its invoice_type "
                .'has no mydata_type set. Configure on the InvoiceType resource.'
            );

        // Movement-only 9.x (Δελτία Αποστολής) are NOT monetary documents — they must
        // go through DeliveryNoteSubmitter, never the monetary invoice builder. The UI
        // picker already excludes them; this guards CLI/API/imported callers (MYD-003).
        if (Codes::isMovementOnlyType($type)) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has a movement-only type ({$type}, Δελτίο Αποστολής) "
                .'and cannot be filed as a monetary invoice — issue it through the Delivery Notes flow.'
            );
        }

        $vatBreakdown = InvoiceVatBreakdown::for($invoice);

        $issuer = (new Issuer)
            ->setVatNumber($this->tenant->afm ?? throw new RuntimeException('Issuer company has no AFM'))
            ->setCountry(CountryCode::GR)
            ->setBranch(0);

        $counterpart = $this->buildCounterpart($invoice, $type);

        $header = (new InvoiceHeader)
            // MYD-018: the FROZEN series, never the editable lookup.
            ->setSeries($invoice->filedSeries() ?? throw new RuntimeException(
                "Invoice {$invoice->invcode} has no series to file under."
            ))
            ->setAa((string) $invoice->code)
            ->setIssueDate(Carbon::parse($invoice->issued_at)->toDateString())
            ->setInvoiceType($type)
            ->setCurrency(CurrencyCode::EUR);

        // Credit note: correlate to the original invoice's MARK so AADE
        // links the credit to the document it reverses. (int) is safe on
        // 64-bit PHP — AADE MARKs are ~15 digits, well under PHP_INT_MAX.
        //
        // BUT only for CORRELATED credit types (5.1). For NON-correlated
        // types (5.2) AADE FORBIDS <correlatedInvoices> and rejects the
        // filing — so we must not send it even though we have an original.
        // (myip's ΠΙΣ historically maps to 5.2; sandbox validated 5.1.)
        if ($invoice->credited_invoice_id !== null
            && ! Codes::isNonCorrelatedCreditType((string) $invoice->invoiceType?->mydata_type)) {
            $header->addCorrelatedInvoice((int) $this->originalInsertMark($invoice));
        }

        // Income classification (E3_561_xxx + categoryN_x). AADE requires it for
        // income documents at the per-line level AND aggregated on the summary —
        // verified against an imported legacy MARK request that AADE accepted (it
        // carried the classification at BOTH levels).
        //
        // MYD-5: the class is resolved PER LINE. The invoice type carries the
        // default (channel-driven E3 type + category); a line's product CATEGORY
        // may override it as a coherent (class, category) pair, so a mixed
        // goods+services invoice files each line under its own class instead of
        // one class for the whole document. The summary then emits one
        // <incomeClassification> per distinct (class, category), each summing its
        // lines' (header-discounted) nets — which still total totalNet (MYD-1).
        $typeClass = $invoice->invoiceType?->mydata_income_class;
        $typeCat = $invoice->invoiceType?->mydata_income_class_category;
        $summaryIncome = [];   // "class|cat" => ['class'=>, 'cat'=>, 'net'=>]

        // MYD-1: per-line net/vat with the header (invoice-level) discount
        // folded in, summing EXACTLY to the InvoiceVatBreakdown rows the
        // summary uses — see allocateDiscountedLineAmounts(). Emitting the
        // raw line values on a discounted invoice made Σ(lines) ≠ totals and
        // AADE rejected with [207]/[209].
        $lineAmounts = $this->allocateDiscountedLineAmounts($invoice, $vatBreakdown);

        // G5: per-line <quantity> is FORBIDDEN for the service types we file
        // ([205]) but expected on goods παραστατικά. Spec §5.x: quantity is
        // optional at the XSD level, so goods types opt in via
        // invoice_types.mydata_requires_quantity; service types (default off)
        // stay byte-identical to the sandbox-validated payload.
        $emitQuantity = (bool) ($invoice->invoiceType?->mydata_requires_quantity ?? false);

        $details = [];
        $lineNo = 1;
        foreach ($invoice->lines->values() as $i => $line) {
            $rate = (float) $line->vat_percent;
            $detail = (new InvoiceDetails)
                ->setLineNumber($lineNo++)
                ->setNetValue($lineAmounts[$i]['net'])
                ->setVatCategory($this->resolveVatCategoryCode($rate))
                ->setVatAmount($lineAmounts[$i]['vat']);

            if ($emitQuantity) {
                // measurementUnit stays omitted (optional per spec; mapping
                // free-text metric_unit → §8.13 codes is a follow-up).
                $detail->setQuantity((float) $line->qty);
            }

            // Opt-in <itemDescr>: myDATA does NOT require it (the legacy app
            // never sent it — verified against imported legacy MARK XML, which
            // carries only the E3 income classification per line), so default
            // OFF keeps the payload byte-identical to the sandbox-validated
            // shape. Even WITH the knob on, AADE ACCEPTS itemDescr only for
            // delivery-note / shipping types (9.x) — it REJECTS it on a plain
            // ΤΠΥ/ΤΙΜ (spec line 1287) — so Codes::allowsItemDescr() gates it by
            // document type; the knob can never produce a rejection. 256-char
            // clamp matches the product_descr column width (and WhmcsInvoiceMapper).
            //
            // NOTE (MYD-003): allowsItemDescr() is true ONLY for 9.x, and build()
            // now REJECTS a 9.x type up-front (a movement note is not a monetary
            // invoice), so this branch is currently UNREACHABLE on the monetary
            // path — delivery-note itemDescr is emitted by DeliveryNoteSubmitter
            // instead. It is kept as defensive code for the day a combined
            // invoice+delivery (1.1 with isDeliveryNote=true) is modelled, at which
            // point allowsItemDescr() must gate on that flag rather than the 9.x
            // type. See docs/BACKLOG.md.
            if ($this->tenant->mydata_send_item_descr
                && Codes::allowsItemDescr((string) $invoice->invoiceType?->mydata_type)
                && filled($line->product_descr)) {
                $detail->setItemDescr(mb_substr((string) $line->product_descr, 0, 256));
            }

            // G4: a 0% line is filed as vatCategory=7 (exempt) WITH the reason
            // code AADE requires ([217] forbids category 7 without it). The
            // reason lives on the tenant's 0%-rate VatCategory; resolve once.
            if (abs($rate) < 0.01) {
                $detail->setVatExemptionCategory(VatExemption::from($this->resolveVatExemptionCategory($line)));
            }

            [$lineClass, $lineCat] = $this->resolveIncomeClass($line, $typeClass, $typeCat);
            if ($lineClass && $lineCat) {
                // Same discounted net as the line's netValue, so the per-line
                // classifications also sum to the summary classification.
                $detail->addIncomeClassification($lineClass, $lineCat, $lineAmounts[$i]['net']);

                // Accumulate the summary aggregate per distinct (class, category).
                $key = $lineClass.'|'.$lineCat;
                $summaryIncome[$key]['class'] = $lineClass;
                $summaryIncome[$key]['cat'] = $lineCat;
                $summaryIncome[$key]['net'] = ($summaryIncome[$key]['net'] ?? 0) + $lineAmounts[$i]['net'];
            }

            $details[] = $detail;
        }

        // NOTE: deliberately NO <taxesTotals>. In myDATA the
        // taxesTotals/taxes taxType enum is 1=Withholding, 2=Fees,
        // 3=OtherTaxes, 4=StampDuty, 5=Deductions — VAT is NOT among them
        // (it lives per-line via vatCategory/vatAmount and in the summary
        // totalVatAmount). The legacy accepted payload carries no
        // taxesTotals at all. The previous code stuffed VAT into
        // taxType=1, which told AADE there was a withholding tax that
        // didn't match totalWithheldAmount → "[226] withheld sum
        // mismatch". Emit taxesTotals only when real non-VAT taxes are
        // modelled (follow-up: withholding/fees support).

        // AADE's InvoiceSummary XSD requires the intermediate tax-total
        // elements between totalVatAmount and totalGrossValue. Omitting
        // them is rejected with "[101] invalid child element
        // 'totalGrossValue' ... expected 'totalWithheldAmount'". The
        // legacy app sent them as 0.00 (verified against an imported
        // legacy MARK request). Withheld comes from the invoice if set.
        // Tax totals (rounded to match the per-block taxAmounts so a sum-check
        // like [226]/[101] can't trip).
        //
        // Gross adjustment per AADE's [208] reconciliation: totalGrossValue must
        // equal Σ(line gross) + fees + stampDuty + otherTaxes − deductions − withheld.
        // Fees / stamp duty / other taxes INCREASE the gross; deductions DECREASE it;
        // and WITHHOLDING decreases it too — EXCEPT the "informational" prepaid-tax
        // categories §8.4 8/9/10 (architects/engineers/lawyers), which AADE reports
        // but does NOT deduct from gross. This mirrors firebed's SummarizesInvoiceTaxes
        // (getTotalTaxes() subtracts withholding; WithheldPercentCategory::
        // affectsTotalGrossValue() is false only for 8/9/10). Both totalGrossValue AND
        // the paymentMethod amount carry the adjustment, else AADE rejects with [208].
        // (The earlier code NEVER deducted withholding — sandbox-confirmed wrong on
        // 2026-06-10 with category 3 «Αμοιβές Συμβούλων 20%», AADE error [208].)
        $withheld = round((float) ($invoice->withhold_amount ?? 0), 2);
        $fees = round((float) ($invoice->fees_amount ?? 0), 2);
        $stampDuty = round((float) ($invoice->stamp_duty_amount ?? 0), 2);
        $otherTaxes = round((float) ($invoice->other_taxes_amount ?? 0), 2);
        $deductions = round((float) ($invoice->deductions_amount ?? 0), 2);

        // The gross adjustment is the SAME rule the local `payable_total` uses
        // (Invoice::additionalTaxAdjustment) — one source so AADE gross and the
        // ledger's collectible can't diverge. The base differs by design: AADE
        // uses the per-VAT-rate gross, the ledger uses gross_total.
        $grossValue = round($vatBreakdown->totalGross() + $invoice->additionalTaxAdjustment(), 2);

        $summary = (new InvoiceSummary)
            ->setTotalNetValue($vatBreakdown->totalNet())
            ->setTotalVatAmount($vatBreakdown->totalVat())
            ->setTotalWithheldAmount($withheld)
            ->setTotalFeesAmount($fees)
            ->setTotalStampDutyAmount($stampDuty)
            ->setTotalOtherTaxesAmount($otherTaxes)
            ->setTotalDeductionsAmount($deductions)
            ->setTotalGrossValue($grossValue);

        // Summary-level income classification = aggregate of the per-line
        // classifications, one node per distinct (class, category). Σ(group nets)
        // == Σ(line nets) == totalNet (MYD-1), so it can't trip the AADE sum-check.
        foreach ($summaryIncome as $g) {
            $summary->addIncomeClassification($g['class'], $g['cat'], round($g['net'], 2));
        }

        $aade = (new AadeInvoice)
            ->setIssuer($issuer)
            ->setInvoiceHeader($header)
            ->setInvoiceDetails($details)
            ->setInvoiceSummary($summary)
            // paymentMethods is mandatory for the invoice types we file
            // ("[204] Payment Methods is mandatory"). The amount must
            // equal the gross total (the legacy payload sent a single
            // detail with type + gross amount). Type defaults to 3
            // (Μετρητά / cash) until per-tenant payment-method → myDATA
            // type mapping is modelled (follow-up).
            ->addPaymentMethod(
                (new PaymentMethodDetail)
                    ->setType($this->paymentMethodTypeFor($invoice))
                    // Must equal totalGrossValue ([451] payment sum = gross),
                    // including the fees/stamp/otherTaxes/deductions adjustment.
                    ->setAmount($grossValue)
            );

        // G1: withholding (παρακράτηση). When the invoice carries a withheld
        // amount, AADE needs a taxesTotals[taxType=1] block naming the
        // withholding category + amount, matching the summary's
        // totalWithheldAmount (set above). Without it the summary declares a
        // withholding AADE can't account for. Emitted ONLY when there's an
        // amount — standard invoices (the sandbox-validated 4 types) are
        // unaffected.
        $withhold = round((float) ($invoice->withhold_amount ?? 0), 2);
        if ($withhold > 0) {
            $category = $invoice->withhold_category;
            if ($category === null || ! Codes::withholdingCategoryExists((int) $category)) {
                throw new RuntimeException(
                    'Invoice '.$invoice->invcode.' has a withholding amount ('.$withhold.') but no valid '.
                    'withholding category (§8.4, 1–18). Set invoices.withhold_category — it identifies which '.
                    'withholding applies (fees 20%, technicians 4/10%, lawyers 15%, …); it cannot be guessed.'
                );
            }
            $aade->addTaxesTotals(
                (new TaxTotals)
                    ->setTaxType(TaxType::TYPE_1)   // 1 = Παρακρατούμενος φόρος (withholding)
                    ->setTaxCategory(WithheldPercentCategory::from((int) $category))
                    ->setUnderlyingValue($vatBreakdown->totalNet())
                    ->setTaxAmount($withhold)
            );
        }

        // #3c: the remaining taxesTotals taxTypes (fees/otherTaxes/stamp/deductions).
        // Each mirrors withholding: an amount + a §8.x category, emitted only when the
        // amount is > 0, with the matching summary total set above.
        $this->addAdditionalTaxes($aade, $invoice, $vatBreakdown->totalNet());

        if ($counterpart) {
            $aade->setCounterpart($counterpart);
        }

        // Deliberately NO client-supplied <uid>: AADE rejects it with
        // "[273] uid is not allowed. It is generated/provided by myDATA".
        // The legacy accepted payload sent no uid. AADE derives its own
        // deterministic uid (from VAT + date + branch + type + series +
        // AA) and uses THAT for resubmission dedup, so retry-idempotency
        // still holds server-side without us sending guessUid().

        return $aade;
    }

    public function toXml(AadeInvoice $payload): string
    {
        return (new InvoicesDocWriter)->asXml(
            new InvoicesDoc([$payload])
        );
    }

    /**
     * MYD-1: per-line netValue/vatAmount with the invoice-level (header)
     * discount folded in.
     *
     * Lines store net/gross WITHOUT the header discount (the InvoiceLine
     * saving hook applies only the line discount; the header discount is an
     * aggregate-level concern — CLAUDE.md canonical math). The summary,
     * however, comes from InvoiceVatBreakdown WITH the discount applied. So
     * emitting raw line values on a discounted invoice made
     * Σ(line netValue) ≠ totalNetValue → AADE ValidationError [207] (and
     * [209] for VAT). AADE validates the SUMS, not per-line rate arithmetic,
     * so the fix is: discount each line, then reconcile the rounding residue
     * inside each VAT-rate group (one cent at a time, largest lines first)
     * until the group sums land exactly on the breakdown row the summary is
     * built from.
     *
     * With header_discount_percent = 0 this is the identity — the stored 2dp
     * line values already sum exactly to the breakdown rows — so the
     * sandbox-validated payload shape is untouched.
     *
     * @return list<array{net: float, vat: float}> indexed like $invoice->lines->values()
     */
    private function allocateDiscountedLineAmounts(Invoice $invoice, InvoiceVatBreakdown $breakdown): array
    {
        $factor = 1 - ((float) $invoice->header_discount_percent) / 100;
        $lines = $invoice->lines->values();

        // Group line indexes by the same rate key InvoiceVatBreakdown groups by.
        $groups = [];
        foreach ($lines as $i => $line) {
            $groups[(string) $line->vat_percent][] = $i;
        }

        $alloc = [];
        foreach ($groups as $rateKey => $indexes) {
            $rate = (float) $rateKey;

            $nets = [];
            $vats = [];
            foreach ($indexes as $i) {
                $line = $lines[$i];
                $nets[$i] = round(((float) $line->net_price) * $factor, 2);
                $vats[$i] = round(((float) $line->gross_price - (float) $line->net_price) * $factor, 2);
            }

            $this->reconcileGroupToTarget($nets, $breakdown->netAtRate($rate), $invoice, 'netValue');
            $this->reconcileGroupToTarget($vats, $breakdown->vatAtRate($rate), $invoice, 'vatAmount');

            foreach ($indexes as $i) {
                $alloc[$i] = ['net' => $nets[$i], 'vat' => $vats[$i]];
            }
        }

        ksort($alloc);

        return $alloc;
    }

    /**
     * Nudge the group's rounded per-line values by ±0.01 until they sum to
     * the breakdown target ([207]/[209] check the sums exactly). Largest
     * values first so the cent lands where it's proportionally invisible;
     * never pushes a value below zero (the XSD floors netValue/vatAmount at
     * 0). The residue is bounded by ½ cent per line, so running out of
     * eligible lines is pathological — throw rather than file a payload AADE
     * would reject anyway.
     *
     * @param  array<int, float>  $values  keyed by line index, mutated in place
     */
    private function reconcileGroupToTarget(array &$values, float $target, Invoice $invoice, string $field): void
    {
        $cents = (int) round(($target - array_sum($values)) * 100);
        if ($cents === 0) {
            return;
        }

        $step = $cents > 0 ? 0.01 : -0.01;
        $keys = array_keys($values);
        usort($keys, fn ($a, $b) => $values[$b] <=> $values[$a]);

        $remaining = abs($cents);
        $guard = $remaining * count($keys) + count($keys);
        for ($k = 0; $remaining > 0 && $guard > 0; $k++, $guard--) {
            $key = $keys[$k % count($keys)];
            if ($step < 0 && $values[$key] < 0.01) {
                continue; // can't take a cent from a zero line
            }
            $values[$key] = round($values[$key] + $step, 2);
            $remaining--;
        }

        if ($remaining > 0) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode}: cannot reconcile per-line {$field} to the ".
                'header-discounted VAT-breakdown total (rounding residue larger than the lines '.
                'can absorb). Check the line amounts and header_discount_percent.'
            );
        }
    }

    /**
     * myDATA paymentMethods/type for an invoice. Defaults to 3 (Μετρητά
     * / cash) — a safe, always-accepted value — until we model a
     * per-payment-method → myDATA-type mapping on the PaymentMethod
     * lookup. The amount on the detail is the invoice gross.
     */
    /**
     * G9: the AADE §8.12 payment-method type for the filing. Reads the
     * invoice's PaymentMethod.mydata_payment_type (1–8); falls back to 3
     * (Μετρητά / cash) when the method is unmapped or absent — the prior
     * hardcoded behaviour, now only the default rather than the only value.
     * A configured value outside 1–8 falls back to 3 rather than emitting a
     * bad type AADE would reject.
     */
    private function paymentMethodTypeFor(Invoice $invoice): int
    {
        $method = $invoice->paymentMethod;
        $type = $method?->mydata_payment_type;

        if ($type !== null && Codes::paymentMethodExists((int) $type)) {
            return (int) $type;
        }

        // MYD-4 (AUDIT): a method IS chosen but has no valid §8.12 mapping — we
        // fall back to 3 (Μετρητά) rather than block a live filing over a
        // payload-quality issue, but LOG it so the misreport is traceable (and
        // MyDataConfigAudit surfaces the same gap in preflight/go-live). A null
        // method (none chosen) defaults to cash silently — that's not a misreport.
        if ($method !== null) {
            Log::warning('myDATA: payment method has no §8.12 type — filing as cash (3)', [
                'company_id' => $this->tenant->getKey(),
                'invoice_id' => $invoice->getKey(),
                'invcode' => $invoice->invcode,
                'payment_method_id' => $method->getKey(),
                'payment_method' => $method->description,
            ]);
        }

        return 3;
    }

    /**
     * The original invoice's filing MARK, for correlating a credit note (5.1).
     * Resolves from BOTH a direct INSERT and a provider PROVIDER_INSERT row, so a
     * credit against a provider-issued original correlates exactly like one against
     * a directly-filed original (MYD-008). This is the ONE shared resolver used by
     * both the direct (MyDataSubmitter) and provider (GrProviderSubmitter) flows —
     * both build through AadeInvoiceDocument. Mirrors cancel()'s "read the MARK from
     * the audit history, not the mirror column" reasoning and the delivery-note
     * cancel resolver's INSERT/PROVIDER_INSERT rule. `whereNotNull('mark')` excludes
     * rejected/failed attempts (PROVIDER_REJECTED / PROVIDER_FAILED carry a null
     * mark). Refuses if the original was never successfully filed.
     */
    private function originalInsertMark(Invoice $creditNote): string
    {
        // Same-tenant: the original must belong to the credit note's company. The
        // CompanyScope is a no-op off-request (queue/CLI submit), so scope explicitly
        // — a credited_invoice_id pointing at another tenant must never resolve (MYD-008).
        $original = Invoice::query()
            ->where('company_id', $creditNote->company_id)
            ->whereKey($creditNote->credited_invoice_id)
            ->first();
        if (! $original) {
            throw new RuntimeException(
                "Credit note {$creditNote->invcode} references a missing original invoice."
            );
        }

        // Same tenant here too: invoice_id is a global PK so it already pins the
        // invoice, but the schema does not enforce that mydata_marks.company_id
        // agrees with its invoice's company — and the CompanyScope is a no-op
        // off-request. Scope explicitly so an inconsistent audit row belonging to
        // another tenant can never be used as the correlated MARK (MYD-008).
        $mark = MyDataMark::query()
            ->where('company_id', $creditNote->company_id)
            ->where('invoice_id', $original->id)
            ->whereIn('mydata_action', ['INSERT', 'PROVIDER_INSERT'])
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->value('mark');

        if (! $mark) {
            throw new RuntimeException(
                "Cannot file credit note for invoice {$original->invcode} — the original has no "
                .'INSERT/PROVIDER_INSERT MARK on file (never successfully submitted to myDATA, '
                .'direct or via provider). File the original first.'
            );
        }

        // The caller feeds this to addCorrelatedInvoice((int) …); guarantee a pure-numeric
        // MARK so a malformed value fails loudly instead of being silently truncated by the
        // cast (AADE MARKs are ~15-digit numerics — this just makes the contract explicit,
        // now that a provider MARK is also a valid source).
        if (! ctype_digit((string) $mark)) {
            throw new RuntimeException(
                "Original invoice {$original->invcode} has a non-numeric filing MARK ({$mark}) — "
                .'cannot correlate a credit note to it.'
            );
        }

        return (string) $mark;
    }

    /**
     * Decide whether to attach a Counterpart and how to construct it,
     * based on the AADE invoice type.
     *
     *   - Retail types (11.x):    Counterpart is FORBIDDEN.
     *   - B2B / standard types:   Counterpart is REQUIRED.
     *   - Special types (13.x receiver-side, 17.x adjustments): out of
     *                             scope for this issuer flow.
     *
     * Filing the wrong shape (e.g. attaching Counterpart to an 11.2
     * ΑΠΥ for an AFM-bearing customer) is a common AADE rejection.
     */
    /**
     * MYD-5: the (E3 income class, category) pair for a line. Resolved field by
     * field so a product's CATEGORY can override just the goods/services BUCKET
     * (`mydata_income_class_category`, e.g. category1_1 goods vs category1_3
     * services) while the E3 TYPE keeps coming from the invoice type — because the
     * type (E3_561_001 wholesale vs E3_561_003 retail) is CHANNEL-driven, not
     * item-driven, so a fixed per-item type would misfile the same product across
     * channels. A product category MAY also override the E3 type (advanced), but
     * the common mixed-invoice case only sets the bucket. Free-text lines (no
     * product) use the type default for both.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveIncomeClass(InvoiceLine $line, ?string $typeClass, ?string $typeCat): array
    {
        $category = $line->product?->productCategory;

        $class = filled($category?->mydata_income_class) ? $category->mydata_income_class : $typeClass;
        $cat = filled($category?->mydata_income_class_category) ? $category->mydata_income_class_category : $typeCat;

        return [$class, $cat];
    }

    /**
     * MYD-6: reject a counterpart whose country contradicts the invoice type
     * (AADE [242]-[244]) BEFORE filing, with an actionable message instead of the
     * opaque AADE rejection. Only the unambiguous 1.x/2.x sales types are checked
     * (see Codes::counterpartCountryClass).
     */
    private function assertCounterpartCountryMatchesType(Invoice $invoice, string $type, string $country): void
    {
        $expected = Codes::counterpartCountryClass($type);
        if ($expected === null) {
            return;
        }

        $isEu = Codes::isEuCountry($country);
        $ok = match ($expected) {
            'GR' => $country === 'GR',
            'EU' => $country !== 'GR' && $isEu,
            'NON_EU' => ! $isEu,
            default => true,
        };

        if (! $ok) {
            $need = match ($expected) {
                'GR' => 'Ελλάδα (GR)',
                'EU' => 'χώρα ΕΕ εκτός Ελλάδας',
                'NON_EU' => 'χώρα εκτός ΕΕ',
                default => '',
            };
            throw new RuntimeException(
                "Invoice {$invoice->invcode}: ο τύπος {$type} απαιτεί αντισυμβαλλόμενο με χώρα «{$need}», ".
                "αλλά η χώρα είναι «{$country}». Διόρθωσε τη χώρα του πελάτη ή άλλαξε τον τύπο παραστατικού ".
                '(1.1/2.1=εγχώριο, 1.2/2.2=ενδοκοινοτικό, 1.3/2.3=τρίτες χώρες).'
            );
        }
    }

    private function buildCounterpart(Invoice $invoice, string $type): ?Counterpart
    {
        // Retail (Λιανικής) types forbid Counterpart even if customer
        // has an AFM (operator booked a B2B-style customer into a
        // retail receipt — common with WHMCS-originated invoices).
        if (str_starts_with($type, '11.')) {
            return null;
        }

        // MYD-009: the legal counterpart comes from the invoice's FROZEN party
        // snapshot, through the Invoice helpers — never straight off `customer`.
        // This used to read $customer->afm and $customer->name while taking country
        // and address from the snapshot, so the filed party was assembled HALF
        // frozen and HALF live: editing a customer changed the XML of an invoice
        // filed a year earlier, and a credit note that faithfully copied its
        // original's snapshot had it overwritten again by today's customer row.
        $afm = $invoice->counterpartAfm();
        if ($afm === null) {
            // THREE distinguishable states, and the operator needs the right one: the
            // document is filed; there is no customer; or there IS a customer with an
            // ΑΦΜ but it describes a different party, so the fallback is closed. The
            // first cut reported the second message for the third state, telling the
            // operator to fill an ΑΦΜ that was already filled.
            $reason = match (true) {
                $invoice->hasBeenFiled() => ' (the document is already filed, so its snapshot is the only source).',
                $invoice->customer === null => ' and no customer is set.',
                filled(Afm::uniqueKey($invoice->customer->afm)) && ! $invoice->counterpartIsTheLinkedCustomer() => ' — the '
                    .'linked customer has one, but the invoice names a different party, so it cannot be borrowed.',
                default => ' and its customer has none either.',
            };

            throw new RuntimeException(
                "Invoice {$invoice->invcode} (type $type) requires a counterpart ΑΦΜ, but the "
                .'invoice carries none'
                .$reason
                .' Fill «ΑΦΜ» on the invoice (or the customer, before issue), or change the '
                .'invoice type to a retail variant (11.x).'
            );
        }

        // ONE definition of the filing country, on the model — shared with the
        // provider payload and the freeze so the three cannot drift (MYD-009).
        $country = $invoice->counterpartCountryForFiling();

        // MYD-6: pre-empt AADE's opaque [242]-[244] ("counterpart country for this
        // invoice type must be Greece / EU-not-Greece / non-EU") with a clear error.
        $this->assertCounterpartCountryMatchesType($invoice, $type, $country);

        $counterpart = (new Counterpart)
            ->setVatNumber($afm)
            ->setCountry($country)
            ->setBranch(0);

        // AADE rule (vendor/firebed/aade-mydata/src/Models/Party.php
        // docblocks): `name` and `address` are FORBIDDEN for GR
        // counterparts and REQUIRED for non-GR. Missing them on a
        // foreign Counterpart causes AADE 4xx with an opaque message.
        if ($country !== 'GR') {
            $counterpart->setName(
                $invoice->counterpartName()
                ?? throw new RuntimeException(
                    "Foreign counterpart on invoice {$invoice->invcode} requires a counterpart name "
                    .'(AADE rule). Fill «Επωνυμία» on the invoice.'
                )
            );

            // MYD-6: a REAL address is required. The old code fabricated
            // 'Unknown'/'00000' placeholders — AADE accepts them but they file
            // garbage onto a legal document (and it was inconsistent with
            // DeliveryNoteSubmitter::requireAddress, which hard-fails). Refuse
            // instead, so the operator fills the customer's real address.
            // Same rule as the identity above: the address is part of the legal
            // counterpart, so a FILED document reads only its own snapshot.
            $live = $invoice->mayFallBackToLiveCustomer() ? $invoice->customer : null;
            $street = $invoice->address1 ?: $live?->address1;
            $city = $invoice->city ?: $live?->city;
            $postcode = $invoice->postcode ?: $live?->postcode;
            if (blank($street) || blank($city) || blank($postcode)) {
                throw new RuntimeException(
                    "Foreign counterpart on invoice {$invoice->invcode} requires a full address ".
                    '(οδός/πόλη/Τ.Κ.) — AADE rejects a missing one. Fill the customer address.'
                );
            }
            $counterpart->setAddress(
                (new Address)
                    ->setStreet($street)
                    ->setCity($city)
                    ->setPostalCode($postcode)
            );
        }

        return $counterpart;
    }

    /**
     * Map a numeric VAT rate to AADE's VatCategory enum (1..8).
     * Values from AADE myDATA spec — kept conservative; unknown
     * rates throw so we don't silently file with the wrong category.
     *
     * 0% → category 7 (Άνευ ΦΠΑ / exempt). The REASON (§8.3) is NOT chosen
     * here — the caller attaches it per-line via setVatExemptionCategory,
     * resolved from the tenant's 0%-rate VatCategory (resolveVatExemptionCategory).
     * That resolution is what guards against filing an unexplained exempt line.
     */
    /**
     * Emit taxesTotals[taxType=2..5] (fees / otherTaxes / stampDuty / deductions)
     * for any that carry an amount. Mirrors the withholding block: amount + a §8.x
     * category (validated against the firebed enum where one exists; deductions has
     * none → a positive int is required). The summary totals are set from the same
     * columns. Throws — never guesses a category — when an amount lacks a valid one.
     */
    private function addAdditionalTaxes(AadeInvoice $aade, Invoice $invoice, float $underlyingValue): void
    {
        // [amount col, category col, TaxType, enum class|null (null = deductions, no
        //  firebed enum → int>0), human §ref for the error message]
        $taxes = [
            ['fees_amount', 'fees_category', TaxType::TYPE_2, FeesPercentCategory::class, 'τελών (§8.7)'],
            ['other_taxes_amount', 'other_taxes_category', TaxType::TYPE_3, OtherTaxesPercentCategory::class, 'λοιπών φόρων (§8.5)'],
            ['stamp_duty_amount', 'stamp_duty_category', TaxType::TYPE_4, StampCategory::class, 'Ψηφιακού Τέλους Συναλλαγής (§8.6)'],
            ['deductions_amount', 'deductions_category', TaxType::TYPE_5, null, 'κρατήσεων'],
        ];

        foreach ($taxes as [$amountCol, $categoryCol, $taxType, $enum, $ref]) {
            $amount = round((float) ($invoice->{$amountCol} ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $category = $invoice->{$categoryCol};
            $valid = $category !== null
                && ($enum === null ? (int) $category > 0 : $enum::tryFrom((int) $category) !== null);

            if (! $valid) {
                throw new RuntimeException(
                    'Invoice '.$invoice->invcode.' has a '.$ref.' amount ('.$amount.') but no valid '.
                    'category in '.$categoryCol.'. AADE needs the category to file the taxesTotals block; '.
                    'it cannot be guessed.'
                );
            }

            $aade->addTaxesTotals(
                (new TaxTotals)
                    ->setTaxType($taxType)
                    ->setTaxCategory((int) $category)
                    ->setUnderlyingValue($underlyingValue)
                    ->setTaxAmount($amount)
            );
        }
    }

    /** @var array<string,?int> memoised per-rate myDATA category override */
    private array $mydataCategoryOverrideCache = [];

    /**
     * The AADE §8.2 vatCategory code for a line rate: the tenant's explicit
     * override if set on the matching VatCategory, else derived from the rate.
     * Resolves the 4%→6-vs-10 (and 3%→9) ν.5057 ambiguity without guessing.
     */
    private function resolveVatCategoryCode(float $rate): int
    {
        return $this->mydataCategoryOverride($rate) ?? $this->vatCategoryFor($rate);
    }

    /**
     * Explicit §8.2 override on the tenant's VatCategory at this rate, or null to
     * fall back to rate-derivation. Throws if two same-rate categories disagree
     * (the rate alone can't pick) or the stored code isn't a valid §8.2 category —
     * symmetric with resolveVatExemptionCategory().
     *
     * Scoped to the ONLY ambiguous rates (3% → 9, 4% → 6/10, ν.5057/2023). For every
     * other rate the §8.2 code is unambiguous, so a stray override (mis-mapped ETL
     * import, direct-DB write) must NOT hijack it — e.g. an override left on a 0%
     * row would replace category 7 (+ its exemption reason → AADE [217]), or one on
     * a 24% row would file it as 4%. Outside 3%/4% we ignore overrides entirely.
     */
    private function mydataCategoryOverride(float $rate): ?int
    {
        if (abs($rate - 3) >= 0.01 && abs($rate - 4) >= 0.01) {
            return null;
        }

        $key = number_format($rate, 2, '.', '');
        if (array_key_exists($key, $this->mydataCategoryOverrideCache)) {
            return $this->mydataCategoryOverrideCache[$key];
        }

        $codes = VatCategory::query()
            ->where('company_id', $this->tenant->id)
            ->whereBetween('rate', [$rate - 0.01, $rate + 0.01])
            ->whereNotNull('mydata_vat_category')
            ->pluck('mydata_vat_category')
            ->map(fn ($c) => (int) $c)
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return $this->mydataCategoryOverrideCache[$key] = null;
        }
        if ($codes->count() > 1) {
            throw new RuntimeException(
                'Multiple '.$key.'%-rate VAT categories set different myDATA category overrides ('.
                $codes->implode(', ').'). Lines store only the rate, so the correct §8.2 code is '.
                'ambiguous — keep one override per rate (Setup → VAT Categories).'
            );
        }

        $code = (int) $codes->first();
        if (AadeVatCategory::tryFrom($code) === null) {
            throw new RuntimeException(
                'A '.$key.'%-rate VAT category has mydata_vat_category='.$code.', which is not a '.
                'valid AADE §8.2 vatCategory (1–10). Fix it in Setup → VAT Categories.'
            );
        }

        return $this->mydataCategoryOverrideCache[$key] = $code;
    }

    private function vatCategoryFor(float $rate): int
    {
        return match (true) {
            abs($rate - 24) < 0.01 => 1,  // 24% standard
            abs($rate - 13) < 0.01 => 2,  // 13% reduced
            abs($rate - 6) < 0.01 => 3,   // 6% super-reduced
            abs($rate - 17) < 0.01 => 4,  // 17% (islands)
            abs($rate - 9) < 0.01 => 5,   // 9% (islands)
            abs($rate - 4) < 0.01 => 6,   // 4% (islands)
            // G4: 0% → category 7 (Άνευ ΦΠΑ). The exemption REASON is attached
            // separately on the line (setVatExemptionCategory); resolving it is
            // what guards against filing an unexplained exempt line.
            abs($rate - 0) < 0.01 => 7,
            default => throw new RuntimeException(
                "VAT rate {$rate}% has no AADE VatCategory mapping. ".
                'Configure the VAT category on the lookup resource, or use category 8 (no VAT) manually.'
            ),
        };
    }

    /**
     * G4: resolve the VAT exemption reason (§8.3, 1–31) for this tenant's 0%
     * lines. Because invoice_lines store only vat_percent (no per-line VAT
     * category), the reason lives on the tenant's 0%-rate VatCategory. We take
     * the single configured exemption; if none is set, or several 0% categories
     * disagree, we throw with operator guidance rather than file a wrong/blank
     * reason. Memoised per submit.
     */
    /**
     * MYD-007: the §8.3 exemption reason for a 0% LINE. The per-line snapshot
     * (`invoice_lines.vat_exemption_category`, chosen at issue) wins — the reason
     * differs by case, so it is captured per line, not tenant-wide. Only when a
     * line carries no snapshot (a legacy/imported invoice, or a tenant that kept a
     * single 0% category) do we fall back to the tenant-wide resolver below. This
     * removes the old "multiple 0% categories → ambiguous → throw" limitation for
     * any invoice whose lines carry their reason.
     */
    private function resolveVatExemptionCategory(InvoiceLine $line): int
    {
        $lineCode = $line->vat_exemption_category;
        if ($lineCode !== null && $lineCode !== '') {
            $code = (int) $lineCode;
            if (! Codes::vatExemptionExists($code)) {
                throw new RuntimeException(
                    'Invoice line '.$line->getKey().' has vat_exemption_category='.$code.
                    ', which is not a valid AADE exemption reason (§8.3, 1–31). Fix the line’s '.
                    'exemption reason before issuing.'
                );
            }

            return $code;
        }

        return $this->resolveTenantWideExemptionCategory();
    }

    /**
     * Legacy fallback: the tenant's SINGLE 0%-rate VatCategory reason. Used only
     * for a line with no per-line snapshot. Still throws on none / multiple — the
     * latter is now only reachable by an old invoice on a tenant that has since
     * added a second 0% category, and the throw tells the operator to set the
     * reason on the line explicitly.
     */
    private function resolveTenantWideExemptionCategory(): int
    {
        if ($this->resolvedExemptionCategory !== null) {
            return $this->resolvedExemptionCategory;
        }

        $codes = VatCategory::query()
            ->where('company_id', $this->tenant->id)
            ->where('rate', 0)
            ->whereNotNull('vat_exemption_category')
            ->pluck('vat_exemption_category')
            ->map(fn ($c) => (int) $c)
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            throw new RuntimeException(
                'This invoice has a 0% / exempt line, but no 0%-rate VAT category has a '.
                'vat_exemption_category set. AADE requires an exemption reason (§8.3, 1–31) for '.
                'vatCategory=7. Set it on the 0%-rate VAT category (Setup → VAT Categories), '.
                'e.g. intra-community supply, export, or άρθρο 39α.'
            );
        }
        if ($codes->count() > 1) {
            throw new RuntimeException(
                'This 0% line has no per-line exemption reason and the tenant has multiple 0%-rate '.
                'VAT categories with different reasons ('.$codes->implode(', ').') — so the correct '.
                'reason is ambiguous. Set the §8.3 reason on the line (edit the invoice: the 0% line '.
                'now has an «Αιτία απαλλαγής» field), then re-issue.'
            );
        }

        $code = (int) $codes->first();
        if (! Codes::vatExemptionExists($code)) {
            // Belt-and-suspenders: the Filament form restricts to §8.3 (1–31),
            // but an ETL/direct-DB write could store an out-of-range value the
            // unsignedTinyInteger column tolerates (0–255). Fail loud-and-
            // friendly here rather than let VatExemption::from() throw a raw
            // ValueError — symmetric with the withholding-category guard.
            throw new RuntimeException(
                'The 0%-rate VAT category has vat_exemption_category='.$code.', which is not a '.
                'valid AADE exemption reason (§8.3, 1–31). Fix it in Setup → VAT Categories.'
            );
        }

        return $this->resolvedExemptionCategory = $code;
    }
}
