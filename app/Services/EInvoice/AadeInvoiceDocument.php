<?php

namespace App\Services\EInvoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\InvoiceVatBreakdown;
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
        $invoice->loadMissing(['lines', 'invoiceType', 'customer']);

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

        $vatBreakdown = InvoiceVatBreakdown::for($invoice);

        $issuer = (new Issuer)
            ->setVatNumber($this->tenant->afm ?? throw new RuntimeException('Issuer company has no AFM'))
            ->setCountry(CountryCode::GR)
            ->setBranch(0);

        $counterpart = $this->buildCounterpart($invoice, $type);

        $header = (new InvoiceHeader)
            ->setSeries($invoice->invoiceType->code)
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

        // Income classification (E3_561_xxx + categoryN_x) comes from the
        // InvoiceType config. AADE requires it for income documents at the
        // per-line level AND aggregated on the summary — verified against
        // an imported legacy MARK request that AADE accepted (it carried
        // the classification at BOTH levels). One class per invoice type,
        // so the per-line amount is just the line net.
        $incomeClass = $invoice->invoiceType?->mydata_income_class;
        $incomeCat = $invoice->invoiceType?->mydata_income_class_category;

        // G5: per-line <quantity> is FORBIDDEN for the service types we file
        // ([205]) but expected on goods παραστατικά. Spec §5.x: quantity is
        // optional at the XSD level, so goods types opt in via
        // invoice_types.mydata_requires_quantity; service types (default off)
        // stay byte-identical to the sandbox-validated payload.
        $emitQuantity = (bool) ($invoice->invoiceType?->mydata_requires_quantity ?? false);

        $details = [];
        $lineNo = 1;
        foreach ($invoice->lines as $line) {
            $rate = (float) $line->vat_percent;
            $detail = (new InvoiceDetails)
                ->setLineNumber($lineNo++)
                ->setNetValue((float) $line->net_price)
                ->setVatCategory($this->resolveVatCategoryCode($rate))
                ->setVatAmount(round((float) $line->gross_price - (float) $line->net_price, 2));

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
            if ($this->tenant->mydata_send_item_descr
                && Codes::allowsItemDescr((string) $invoice->invoiceType?->mydata_type)
                && filled($line->product_descr)) {
                $detail->setItemDescr(mb_substr((string) $line->product_descr, 0, 256));
            }

            // G4: a 0% line is filed as vatCategory=7 (exempt) WITH the reason
            // code AADE requires ([217] forbids category 7 without it). The
            // reason lives on the tenant's 0%-rate VatCategory; resolve once.
            if (abs($rate) < 0.01) {
                $detail->setVatExemptionCategory(VatExemption::from($this->resolveVatExemptionCategory()));
            }

            if ($incomeClass && $incomeCat) {
                $detail->addIncomeClassification($incomeClass, $incomeCat, (float) $line->net_price);
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

        // Withholding reduces the gross unless its §8.4 category is informational (8/9/10).
        $withheldReducesGross = $withheld > 0
            && ($withheldCat = $invoice->withhold_category) !== null
            && WithheldPercentCategory::tryFrom((int) $withheldCat)?->affectsTotalGrossValue() === true;
        $withheldGrossAdjust = $withheldReducesGross ? $withheld : 0.0;

        $grossValue = round(
            $vatBreakdown->totalGross() + $fees + $stampDuty + $otherTaxes - $deductions - $withheldGrossAdjust,
            2,
        );

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
        // classifications (single class per invoice type → total net).
        if ($incomeClass && $incomeCat) {
            $summary->addIncomeClassification($incomeClass, $incomeCat, $vatBreakdown->totalNet());
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
        $type = $invoice->paymentMethod?->mydata_payment_type;

        return ($type !== null && Codes::paymentMethodExists((int) $type)) ? (int) $type : 3;
    }

    /**
     * The original invoice's INSERT MARK, for correlating a credit note.
     * Mirrors cancel()'s "read MARK from the audit history, not the
     * mirror column" reasoning. Refuses if the original was never filed
     * (can't correlate a credit to an unfiled document).
     */
    private function originalInsertMark(Invoice $creditNote): string
    {
        $original = Invoice::query()->whereKey($creditNote->credited_invoice_id)->first();
        if (! $original) {
            throw new RuntimeException(
                "Credit note {$creditNote->invcode} references a missing original invoice."
            );
        }

        $mark = MyDataMark::query()
            ->where('invoice_id', $original->id)
            ->where('mydata_action', 'INSERT')
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->value('mark');

        if (! $mark) {
            throw new RuntimeException(
                "Cannot file credit note for invoice {$original->invcode} — the original has no "
                .'INSERT MARK on file (never submitted to myDATA). File the original first.'
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
    private function buildCounterpart(Invoice $invoice, string $type): ?Counterpart
    {
        // Retail (Λιανικής) types forbid Counterpart even if customer
        // has an AFM (operator booked a B2B-style customer into a
        // retail receipt — common with WHMCS-originated invoices).
        if (str_starts_with($type, '11.')) {
            return null;
        }

        $customer = $invoice->customer;
        if (! $customer || empty($customer->afm)) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} (type $type) requires a customer with AFM, but ".
                ($customer ? 'AFM is empty' : 'no customer is set').'. '.
                'Either fill the customer AFM, or change the invoice type to a retail variant (11.x).'
            );
        }

        // Country: prefer the invoice snapshot (the legally-frozen
        // value at issue time); fall back to live customer country,
        // then 'GR'. Normalise to ISO-3166-1 alpha-2 — AADE rejects
        // anything else, including spelled-out names ("Greece").
        $country = $this->normaliseCountryCode($invoice->country ?: $customer->country ?: 'GR');

        $counterpart = (new Counterpart)
            ->setVatNumber($customer->afm)
            ->setCountry($country)
            ->setBranch(0);

        // AADE rule (vendor/firebed/aade-mydata/src/Models/Party.php
        // docblocks): `name` and `address` are FORBIDDEN for GR
        // counterparts and REQUIRED for non-GR. Missing them on a
        // foreign Counterpart causes AADE 4xx with an opaque message.
        if ($country !== 'GR') {
            $counterpart->setName(
                $customer->name
                ?? throw new RuntimeException(
                    "Foreign counterpart on invoice {$invoice->invcode} requires customer name (AADE rule)."
                )
            );
            $counterpart->setAddress(
                (new Address)
                    ->setStreet($invoice->address1 ?: ($customer->address1 ?: 'Unknown'))
                    ->setCity($invoice->city ?: ($customer->city ?: 'Unknown'))
                    ->setPostalCode($invoice->postcode ?: ($customer->postcode ?: '00000'))
            );
        }

        return $counterpart;
    }

    /**
     * Normalise a free-text country string to ISO-3166-1 alpha-2.
     * Real-world data is messy: operators type "Greece", "Ελλάδα",
     * "Hellas", "GR", "GRC" — AADE only accepts the 2-letter code.
     * Defensive: throw on unrecognised input rather than send
     * gibberish that AADE rejects opaquely. Keep this list focused on
     * the countries we actually have tenants/customers in; add cases
     * as needed.
     */
    private function normaliseCountryCode(string $raw): string
    {
        $trimmed = trim(mb_strtoupper($raw));
        // Already in alpha-2 shape
        if (strlen($trimmed) === 2 && ctype_alpha($trimmed)) {
            return $trimmed;
        }

        return match ($trimmed) {
            'GREECE', 'HELLAS', 'ΕΛΛΑΔΑ', 'ΕΛΛΆΔΑ', 'GRC' => 'GR',
            'ESTONIA', 'EESTI', 'EST' => 'EE',
            'CYPRUS', 'ΚΥΠΡΟΣ', 'CYP' => 'CY',
            'GERMANY', 'DEUTSCHLAND', 'ΓΕΡΜΑΝΙΑ', 'DEU' => 'DE',
            default => throw new RuntimeException(
                "Cannot normalise country '{$raw}' to ISO-3166-1 alpha-2. ".
                'Update the customer/invoice country to a 2-letter code, '.
                'or extend AadeInvoiceDocument::normaliseCountryCode().'
            ),
        };
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
            ['fees_amount', 'fees_category', TaxType::TYPE_2, FeesPercentCategory::class, 'τελών (§8.5)'],
            ['other_taxes_amount', 'other_taxes_category', TaxType::TYPE_3, OtherTaxesPercentCategory::class, 'λοιπών φόρων (§8.6)'],
            ['stamp_duty_amount', 'stamp_duty_category', TaxType::TYPE_4, StampCategory::class, 'χαρτοσήμου (§8.7)'],
            ['deductions_amount', 'deductions_category', TaxType::TYPE_5, null, 'κρατήσεων (§8.8)'],
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
    private function resolveVatExemptionCategory(): int
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
                'Multiple 0%-rate VAT categories have different exemption reasons ('.
                $codes->implode(', ').'). Invoice lines store only the rate, not which exempt '.
                'category, so the correct reason is ambiguous. Keep a single 0%-rate VAT category '.
                'per tenant (or split filing by reason — a follow-up if a tenant truly needs both).'
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
