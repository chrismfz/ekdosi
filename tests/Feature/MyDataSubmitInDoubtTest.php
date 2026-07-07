<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\MyDataSubmitter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MYD-2 (AUDIT, σκέλος γ) — the "in-doubt" gate.
 *
 * A submission whose TRANSPORT failed (timeout / connection drop) leaves the
 * filing AMBIGUOUS: the POST may have reached AADE and produced a MARK we never
 * saw. AADE does NOT dedup a resubmission of the same (series, ΑΑ) — proven on
 * the sandbox 2026-07-07: the same invoiceUid yielded TWO distinct MARKs. So a
 * blind retry double-declares income.
 *
 * The gate: on transport failure the invoice is flagged mydata_pending_since;
 * the NEXT submit() reconciles against RequestTransmittedDocs FIRST and ADOPTS
 * an existing live MARK instead of filing a second one — and only files when
 * AADE genuinely has nothing.
 *
 * The MockHandler is a strict queue: if the code ever attempts a SendInvoices
 * POST it wasn't supposed to, there's no queued response and the test fails —
 * which is exactly the "no second submission" guarantee we want to assert.
 */
class MyDataSubmitInDoubtTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'InDoubt', 'slug' => 'indoubt-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800561849', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 2, 'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'afm' => '012816391']);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        $this->invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'local_status' => 'active', 'country' => 'GR',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id,
            'qty' => 1, 'vat_percent' => 24, 'net_price' => 100, 'gross_price' => 124,
        ]);
    }

    /** Flag the invoice in-doubt as of $minutesAgo minutes ago. */
    private function markInDoubt(int $minutesAgo): void
    {
        $this->invoice->forceFill(['mydata_pending_since' => now()->subMinutes($minutesAgo)])->save();
    }

    /** A RequestTransmittedDocs response carrying ONE live doc for (series, ΑΑ). */
    private function transmittedDocsMock(string $series, string $aa, string $mark): GuzzleResponse
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns:pm="https://www.aade.gr/myDATA/paymentMethod/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
          <invoicesDoc>
            <invoice>
              <uid>E230F0CFCC82356FE38C9F085A86A6E7F421EAD1</uid>
              <mark>{$mark}</mark>
              <issuer>
                <vatNumber>800561849</vatNumber>
                <country>GR</country>
                <branch>0</branch>
              </issuer>
              <counterpart>
                <vatNumber>012816391</vatNumber>
                <country>GR</country>
                <branch>0</branch>
              </counterpart>
              <invoiceHeader>
                <series>{$series}</series>
                <aa>{$aa}</aa>
                <issueDate>2026-07-07</issueDate>
                <invoiceType>2.1</invoiceType>
                <currency>EUR</currency>
              </invoiceHeader>
              <invoiceDetails>
                <lineNumber>1</lineNumber>
                <netValue>100</netValue>
                <vatCategory>1</vatCategory>
                <vatAmount>24</vatAmount>
              </invoiceDetails>
              <invoiceSummary>
                <totalNetValue>100</totalNetValue>
                <totalVatAmount>24</totalVatAmount>
                <totalWithheldAmount>0</totalWithheldAmount>
                <totalFeesAmount>0</totalFeesAmount>
                <totalStampDutyAmount>0</totalStampDutyAmount>
                <totalOtherTaxesAmount>0</totalOtherTaxesAmount>
                <totalDeductionsAmount>0</totalDeductionsAmount>
                <totalGrossValue>124</totalGrossValue>
              </invoiceSummary>
            </invoice>
          </invoicesDoc>
        </RequestedDoc>
        XML;

        return new GuzzleResponse(200, [], $xml);
    }

    /** An empty RequestTransmittedDocs response — AADE has nothing for the window. */
    private function emptyTransmittedDocsMock(): GuzzleResponse
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"></RequestedDoc>';

        return new GuzzleResponse(200, [], $xml);
    }

    public function test_in_doubt_invoice_adopts_existing_mark_without_resubmitting(): void
    {
        // Adoption happens whenever a live MARK is found — grace window irrelevant.
        $this->markInDoubt(1);
        $adoptedMark = '400001965177931';

        // ONLY a RequestTransmittedDocs response is queued. If submit() tried to
        // POST a SendInvoices, the queue would be empty and this would blow up.
        $mock = new MockHandler([
            $this->transmittedDocsMock('TPY', '1', $adoptedMark),
        ]);

        $mark = (new MyDataSubmitter($this->tenant, $mock))->submit($this->invoice->fresh('lines'));

        // Adopted the AADE MARK, no second filing.
        $this->assertSame($adoptedMark, (string) $mark->mark);
        $this->assertSame('INSERT', $mark->mydata_action);

        $fresh = $this->invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame($adoptedMark, (string) $fresh->mydata_mark);
        $this->assertNull($fresh->mydata_pending_since, 'in-doubt flag must be cleared after adoption');
        $this->assertSame('active', $fresh->local_status);

        // Exactly ONE INSERT row for this invoice+mark (idempotent adopt).
        $this->assertSame(1, MyDataMark::where('invoice_id', $this->invoice->id)
            ->where('mydata_action', 'INSERT')->where('mark', $adoptedMark)->count());

        // The invoice-type counter was NOT bumped (no ΑΑ was allocated/burned).
        $this->assertSame(2, $this->type->fresh()->invcount);

        // The reconcile response was consumed and nothing else was attempted.
        $this->assertSame(0, $mock->count(), 'no further AADE calls (no resubmission) should have happened');
    }

    public function test_in_doubt_past_grace_files_normally_when_aade_has_nothing(): void
    {
        // In-doubt LONGER than the grace window (default 10 min) AND AADE has
        // nothing → the earlier POST is deemed lost, so we file normally.
        $this->markInDoubt(20);

        $sendXml = file_get_contents(base_path('vendor/firebed/aade-mydata/stubs/send-invoices-single-response.xml'));

        // First the (empty) reconcile lookup, THEN a real SendInvoices filing.
        $mock = new MockHandler([
            $this->emptyTransmittedDocsMock(),
            new GuzzleResponse(200, [], $sendXml),
        ]);

        $mark = (new MyDataSubmitter($this->tenant, $mock))->submit($this->invoice->fresh('lines'));

        $fresh = $this->invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNotNull($mark->mark);
        $this->assertNull($fresh->mydata_pending_since, 'a successful filing clears the in-doubt flag');

        // Both queued responses were used: the lookup AND the real filing.
        $this->assertSame(0, $mock->count());
    }

    public function test_in_doubt_within_grace_refuses_to_resubmit_when_aade_empty(): void
    {
        // Freshly in-doubt (within the grace window). AADE's feed shows nothing —
        // but that may just be indexing lag, so we must NOT resubmit (that's the
        // double-file risk). Only the reconcile lookup is queued: a resubmission
        // attempt would need a second response and blow up differently.
        $this->markInDoubt(1);

        $mock = new MockHandler([
            $this->emptyTransmittedDocsMock(),
        ]);

        try {
            (new MyDataSubmitter($this->tenant, $mock))->submit($this->invoice->fresh('lines'));
            $this->fail('Expected the within-grace in-doubt submit to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }

        // Nothing was filed; the invoice stays in-doubt for a later retry.
        $fresh = $this->invoice->fresh();
        $this->assertNull($fresh->mydata_state);
        $this->assertNotNull($fresh->mydata_pending_since);
        // Only the reconcile lookup was consumed — no SendInvoices was attempted.
        $this->assertSame(0, $mock->count());
    }
}
