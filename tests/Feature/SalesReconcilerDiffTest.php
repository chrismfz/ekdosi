<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\MyData\AadeDocSummary;
use App\Services\MyData\FiledInvoiceTotals;
use App\Services\MyData\SalesReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pure-diff core of the live myDATA sales reconciliation. No network:
 * we hand SalesReconciler::diff() pre-built AADE summaries + local
 * invoices and assert the four buckets. The network fetch + firebed
 * parsing is covered separately by SalesReconcilerFetchTest.
 */
class SalesReconcilerDiffTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private int $code = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Recon test',
            'slug' => 'recon-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Πελάτης ΑΕ',
            'afm' => '123456789',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);
    }

    public function test_diff_sorts_invoices_into_the_four_buckets(): void
    {
        $matchedValid = $this->invoice('400000000000001', 'VALID');
        $matchedCancelled = $this->invoice('400000000000002', 'CANCELLED');
        $aadeCancelledLocalActive = $this->invoice('400000000000003', 'VALID');
        $localCancelledAadeValid = $this->invoice('400000000000004', 'CANCELLED');
        $missingAtAade = $this->invoice('400000000000005', 'VALID');

        $aadeDocs = [
            $this->aadeFor($matchedValid, cancelled: false),
            $this->aadeFor($matchedCancelled, cancelled: true),
            $this->aade('400000000000003', cancelled: true),  // AADE cancelled, local active
            $this->aade('400000000000004', cancelled: false), // AADE valid, local cancelled
            // 400000000000005 deliberately absent → missing at AADE
            $this->aade('400000000000099', cancelled: false), // AADE-only → missing locally
        ];

        $result = (new SalesReconciler($this->tenant))->diff(
            $aadeDocs,
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(2, $result->matched);
        $this->assertCount(2, $result->stateMismatch);
        $this->assertCount(1, $result->missingAtAade);
        $this->assertCount(1, $result->missingLocally);

        $this->assertEqualsCanonicalizing(
            ['400000000000001', '400000000000002'],
            collect($result->matched)->pluck('mark')->all(),
        );

        $this->assertSame('400000000000005', $result->missingAtAade[0]->mark);
        $this->assertSame($missingAtAade->id, $result->missingAtAade[0]->invoiceId);

        $this->assertSame('400000000000099', $result->missingLocally[0]->mark);
        $this->assertNull($result->missingLocally[0]->invoiceId);

        $this->assertSame(5, $result->aadeTotal);
        $this->assertSame(5, $result->localTotal);
        $this->assertSame(4, $result->discrepancyCount());
        $this->assertTrue($result->hasDiscrepancies());
    }

    public function test_state_mismatch_problem_text_is_direction_aware(): void
    {
        $this->invoice('500000000000001', 'VALID');     // local active
        $this->invoice('500000000000002', 'CANCELLED'); // local cancelled

        $result = (new SalesReconciler($this->tenant))->diff(
            [
                $this->aade('500000000000001', cancelled: true),  // AADE cancelled
                $this->aade('500000000000002', cancelled: false), // AADE valid
            ],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $rows = collect($result->stateMismatch)->keyBy('mark');

        $this->assertStringContainsString('Ακυρωμένο στο AADE', $rows['500000000000001']->problem);
        $this->assertStringContainsString('Τοπικά ακυρωμένο', $rows['500000000000002']->problem);
    }

    public function test_local_invoices_without_a_mark_are_ignored(): void
    {
        // Draft (never filed) — no mydata_mark. Must not appear anywhere.
        $this->invoice(null, null);
        $filed = $this->invoice('600000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($filed, cancelled: false)],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertSame(1, $result->localTotal);
        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->missingAtAade);
    }

    public function test_duplicate_local_mark_is_surfaced_not_collapsed(): void
    {
        // Two local invoices sharing one MARK — a data-integrity fault
        // the console must surface (keyBy would otherwise hide one).
        $first = $this->invoice('800000000000001', 'VALID');
        $this->invoice('800000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($first, cancelled: false)],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(2, $result->duplicateLocal);
        $this->assertSame(2, $result->localTotal);
        $this->assertTrue($result->hasDiscrepancies());
        $this->assertSame(2, $result->discrepancyCount());
        // The collapsed survivor still matches AADE, but the collision is flagged.
        $this->assertCount(1, $result->matched);
    }

    public function test_no_discrepancies_when_everything_agrees(): void
    {
        $a = $this->invoice('700000000000001', 'VALID');
        $b = $this->invoice('700000000000002', 'CANCELLED');

        $result = (new SalesReconciler($this->tenant))->diff(
            [
                $this->aadeFor($a, cancelled: false),
                $this->aadeFor($b, cancelled: true),
            ],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertFalse($result->hasDiscrepancies());
        $this->assertCount(2, $result->matched);
    }

    public function test_same_mark_and_state_but_different_gross_is_a_content_mismatch(): void
    {
        // MYD-017: MARK + state agree, but the gross differs beyond the cent
        // tolerance → contentMismatch, NOT a false "matched".
        $inv = $this->invoice('410000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['gross' => 999.00])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $this->assertStringContainsString('μικτό', $result->contentMismatch[0]->problem);
        $this->assertTrue($result->hasDiscrepancies());
        $this->assertSame(1, $result->discrepancyCount());
    }

    public function test_gross_within_a_cent_still_matches(): void
    {
        $inv = $this->invoice('420000000000001', 'VALID'); // gross_total 124.00

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['gross' => 124.009])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
    }

    public function test_different_type_or_series_is_a_content_mismatch(): void
    {
        $inv = $this->invoice('430000000000001', 'VALID');
        $inv->forceFill(['mydata_type' => '1.1'])->save(); // so the type compare runs

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['invoiceType' => '2.1', 'series' => 'ZZZ'])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $problem = $result->contentMismatch[0]->problem;
        $this->assertStringContainsString('τύπος', $problem);
        $this->assertStringContainsString('σειρά', $problem);
    }

    public function test_retail_without_counterpart_afm_still_matches(): void
    {
        // Retail (11.x): AADE returns no counterpart. A local vat_no with no AADE
        // AFM to compare against is NOT a content difference.
        $inv = $this->invoice('440000000000001', 'VALID');
        $inv->forceFill(['vat_no' => '123456789'])->save();

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['counterpartVat' => null])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
        $this->assertCount(0, $result->contentIncomplete);
    }

    public function test_aade_field_absent_locally_is_content_incomplete(): void
    {
        // MYD-017 review: a field AADE carries but the LOCAL doc genuinely lacks —
        // even after the relation fallback — is neither a clean match nor a hard
        // conflict; it's an unverified, INCOMPLETE record. Here the customer has NO
        // ΑΦΜ and vat_no is null, so counterpartVat can't be recovered, yet AADE
        // returns one → contentIncomplete (a warning), never a false green.
        $noAfm = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Πελάτης χωρίς ΑΦΜ',
            'afm' => null,
        ]);
        $inv = $this->invoice('450000000000001', 'VALID', $noAfm); // vat_no null, customer afm null

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['counterpartVat' => '123456789'])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
        $this->assertCount(1, $result->contentIncomplete);
        $this->assertStringContainsString('ΑΦΜ', $result->contentIncomplete[0]->problem);
        $this->assertTrue($result->hasDiscrepancies());
        $this->assertSame(1, $result->discrepancyCount());
    }

    public function test_legacy_invoice_with_null_caches_matches_via_relations(): void
    {
        // MYD-017 review (regression): an ETL-imported invoice carries a real MARK but
        // null denormalised caches (invoices.mydata_type / vat_no) — the ETL snapshots
        // the type onto invoice_types, not each invoice. The comparator must recover
        // the type from the invoiceType relation and the ΑΦΜ from the customer, so the
        // invoice reads as `matched`, NOT a permanent contentIncomplete (which would
        // flip the scheduled reconcile to exit-2 forever on every legacy tenant).
        $inv = $this->invoice('460000000000001', 'VALID'); // mydata_type + vat_no both null

        $this->assertNull($inv->mydata_type);
        $this->assertNull($inv->vat_no);

        $result = (new SalesReconciler($this->tenant))->diff(
            // AADE returns the type ('1.1' — the type relation's mydata_type) and the
            // customer's ΑΦΜ; both are recoverable locally via the relations.
            [$this->aadeFor($inv, override: ['invoiceType' => '1.1', 'counterpartVat' => '123456789'])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentIncomplete);
        $this->assertCount(0, $result->contentMismatch);
    }

    public function test_blank_aade_issue_date_is_content_incomplete_not_matched(): void
    {
        // MYD-017 (AADE-side fail-open): an empty/unparseable AADE issueDate must be
        // neither a conflict (parsing '' as "today" would fabricate one) NOR a green
        // "matched" — we simply could not verify a MANDATORY header field, so the row
        // is contentIncomplete (unverified).
        $inv = $this->invoice('470000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['issueDate' => ''])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
        $this->assertCount(1, $result->contentIncomplete);
        $this->assertStringContainsString('ημ/νία', $result->contentIncomplete[0]->problem);
        $this->assertStringContainsString('ΑΑΔΕ', $result->contentIncomplete[0]->problem);
    }

    public function test_unparseable_aade_issue_date_is_content_incomplete(): void
    {
        // Same rule for a present-but-garbage date: unverifiable, never green.
        $inv = $this->invoice('480000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['issueDate' => 'not-a-date'])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentIncomplete);
    }

    /**
     * Every MANDATORY AADE header field, when the summary does not carry it, must
     * route the row to contentIncomplete instead of silently reading as matched
     * (the AADE-side fail-open). Counterpart ΑΦΜ is deliberately NOT in this list —
     * retail 11.x legitimately has none (covered by its own test).
     */
    public static function mandatoryAadeFieldProvider(): array
    {
        return [
            'gross' => [['gross' => null], 'μικτό'],
            'net' => [['net' => null], 'καθαρή αξία'],
            'invoiceType' => [['invoiceType' => null], 'τύπος'],
            'series' => [['series' => null], 'σειρά'],
            'aa' => [['aa' => null], 'ΑΑ'],
            'issueDate' => [['issueDate' => null], 'ημ/νία'],
        ];
    }

    #[DataProvider('mandatoryAadeFieldProvider')]
    public function test_missing_mandatory_aade_field_is_content_incomplete(array $override, string $label): void
    {
        $inv = $this->invoice('490000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: $override)],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched, "{$label}: must not be a false green");
        $this->assertCount(0, $result->contentMismatch, "{$label}: absence is not a conflict");
        $this->assertCount(1, $result->contentIncomplete);
        $this->assertStringContainsString($label, $result->contentIncomplete[0]->problem);
        $this->assertTrue($result->hasDiscrepancies());
    }

    public function test_same_gross_but_different_net_is_a_content_mismatch(): void
    {
        // MYD-017: the VAT-split false green. Gross agrees to the cent, but the
        // net/VAT split does not (100+24 locally vs 110+14 at AADE) — a wrong VAT
        // category that compensates in gross. Comparing gross alone called this
        // "matched"; comparing net catches it.
        $inv = $this->invoice('510000000000001', 'VALID'); // net 100.00, gross 124.00

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['net' => 110.00])], // same gross 124.00
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $this->assertStringContainsString('καθαρή αξία', $result->contentMismatch[0]->problem);
        // The gross itself is NOT reported as differing — only the split is.
        $this->assertStringNotContainsString('μικτό', $result->contentMismatch[0]->problem);
    }

    public function test_withholding_invoice_matches_the_aade_adjusted_gross(): void
    {
        // Regression: AADE's <totalGrossValue> carries the [208] additional-tax
        // adjustment (here −200 withholding). The comparable local figure is the
        // FILED roll-up + that same adjustment — not gross_total, which stays
        // net+VAT. Comparing gross_total made every withholding invoice (the ΠΚ-3
        // 20% case) a FALSE contentMismatch.
        $inv = $this->invoice('520000000000001', 'VALID', lineNet: 1000.00, lineGross: 1240.00);
        $inv->forceFill([
            'withhold_amount' => 200.00,
            'withhold_category' => 3,   // §8.4 cat 3 — DOES reduce totalGrossValue
        ])->save();
        $inv->refresh()->load('lines');

        $filed = FiledInvoiceTotals::for($inv);
        // Precondition: the filed gross really differs from the ledger gross, so
        // the test cannot pass by coincidence.
        $this->assertSame(1040.0, $filed->gross);
        $this->assertSame(1240.0, round((float) $inv->gross_total, 2));

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['gross' => 1040.00, 'net' => 1000.00])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
        $this->assertCount(0, $result->contentIncomplete);
    }

    public function test_legacy_withholding_without_a_category_is_unverified_not_a_conflict(): void
    {
        // The Firebird ETL copies WITHHOLD_AMOUNT but NO §8.4 category, and whether
        // AADE reduced the gross depends on that category (8/9/10 do not). So the
        // filed gross is genuinely unknowable for an imported ΠΚ-3 invoice: it must
        // read as contentIncomplete (unverified), NOT as a false conflict against a
        // number we never sent — and not as a false green either.
        $inv = $this->invoice('530000000000001', 'VALID', lineNet: 1000.00, lineGross: 1240.00);
        $inv->forceFill([
            'withhold_amount' => 200.00,
            'withhold_category' => null,   // exactly what the ETL leaves behind
        ])->save();
        $inv->refresh()->load('lines');

        $filed = FiledInvoiceTotals::for($inv);
        $this->assertNull($filed->gross, 'gross is not reconstructable without the category');
        $this->assertSame(1000.0, $filed->net, 'net is still reconstructable');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['gross' => 1040.00, 'net' => 1000.00])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
        $this->assertCount(1, $result->contentIncomplete);
        $this->assertStringContainsString('δεν προσδιορίζεται τοπικά', $result->contentIncomplete[0]->problem);
    }

    public function test_multi_rate_discounted_invoice_does_not_false_conflict_on_rounding(): void
    {
        // The ledger columns round ONCE over the whole sum; the filed summary rounds
        // PER VAT RATE. On a multi-rate invoice with a header discount the two shapes
        // differ by a cent or two, which would report a byte-correct document as a
        // content conflict. Comparing the FILED roll-up removes that whole class.
        $inv = $this->invoice('540000000000001', 'VALID', lineNet: 10.05, lineGross: 12.46);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 10.05, 'vat_percent' => 13.00,
            'net_price' => 10.05, 'gross_price' => 11.36, 'product_descr' => 'Δεύτερος συντελεστής',
        ]);
        $inv->forceFill(['header_discount_percent' => 50])->save();
        $inv->refresh()->load('lines');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv)],   // mirrors the FILED roll-up
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
    }

    private function invoice(
        ?string $mark,
        ?string $state,
        ?Customer $customer = null,
        float $lineNet = 100.00,
        float $lineGross = 124.00,
    ): Invoice {
        $this->code++;

        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY'.$this->code,
            'code' => $this->code,
            'invoice_type_id' => $this->type->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
            'net_total' => $lineNet,
            'gross_total' => $lineGross,
        ]);

        // A real line: the money comparison reconstructs the FILED totals from
        // InvoiceVatBreakdown, so a line-less fixture would exercise only the
        // "not reconstructable" path. 100.00 net + 24% = 124.00 gross.
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'qty' => 1,
            'price_per_item' => $lineNet,
            'vat_percent' => 24.00,
            'net_price' => $lineNet,
            'gross_price' => $lineGross,
            'product_descr' => 'Υπηρεσία',
        ]);
        $invoice->load('lines');

        if ($mark !== null) {
            $invoice->forceFill([
                'mydata_mark' => $mark,
                'mydata_state' => $state,
                'mydata_sent' => true,
            ])->save();
        }

        return $invoice;
    }

    /** @return Collection<int, Invoice> */
    private function localCollection(): Collection
    {
        return Invoice::query()
            ->where('company_id', $this->tenant->id)
            ->whereNotNull('mydata_mark')
            ->with('customer', 'invoiceType', 'lines')
            ->get();
    }

    /**
     * An AADE summary NOT backed by a local invoice (missing-locally rows, or
     * state-mismatch rows whose content is never compared). Content is arbitrary.
     */
    private function aade(string $mark, bool $cancelled): AadeDocSummary
    {
        return new AadeDocSummary(
            mark: $mark,
            uid: 'UID-'.$mark,
            cancelled: $cancelled,
            cancelledByMark: $cancelled ? '9'.$mark : null,
            series: 'TPY',
            aa: substr($mark, -3),
            issueDate: '2026-01-15',
            counterpartName: 'Πελάτης ΑΕ',
            counterpartVat: '123456789',
            gross: 124.00,
            net: 100.00,
        );
    }

    /**
     * An AADE summary that MIRRORS a local invoice's content — used for matched
     * rows so the MYD-017 content compare sees no difference. Pass overrides to
     * force a specific field to diverge (→ contentMismatch).
     */
    private function aadeFor(Invoice $inv, bool $cancelled = false, array $override = []): AadeDocSummary
    {
        // array_merge (NOT ??) so an EXPLICIT null override actually wins — the
        // retail case (counterpartVat => null) has to reach AADE as null, not fall
        // back to the invoice's own value.
        // Mirror the SAME relation fallback snapshotFrom() uses (vat_no ?: customer
        // afm, mydata_type ?: type's mydata_type) so a "matched" invoice reconciles
        // even when its denormalised caches are null (legacy/ETL invoices).
        $fields = array_merge([
            'series' => $inv->invoiceType?->code,
            'aa' => (string) $inv->code,
            'issueDate' => $inv->issued_at?->format('Y-m-d'),
            'counterpartVat' => $inv->vat_no ?: $inv->customer?->afm,
            'gross' => FiledInvoiceTotals::for($inv)->gross,
            'net' => FiledInvoiceTotals::for($inv)->net,
            'invoiceType' => $inv->mydata_type ?: $inv->invoiceType?->mydata_type,
        ], $override);

        return new AadeDocSummary(
            mark: (string) $inv->mydata_mark,
            uid: 'UID-'.$inv->mydata_mark,
            cancelled: $cancelled,
            cancelledByMark: $cancelled ? '9'.$inv->mydata_mark : null,
            series: $fields['series'],
            aa: $fields['aa'],
            issueDate: $fields['issueDate'],
            counterpartName: $inv->customer?->name,
            counterpartVat: $fields['counterpartVat'],
            gross: $fields['gross'],
            net: $fields['net'],
            invoiceType: $fields['invoiceType'],
        );
    }
}
