<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Services\MyDataRejected;
use App\Services\MyDataSubmitter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the safety guards on MyDataSubmitter that don't need
 * firebed/Guzzle mocking. The actual SendInvoices HTTP flow is
 * covered by the artisan smoke command + future integration tests;
 * these are pure server-side invariants.
 */
class MyDataSubmitterSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $invoiceType;

    private VatCategory $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Submitter test',
            'slug' => 'sub-safety-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Test customer',
            'afm' => '123456789',
        ]);

        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);

        $this->vat = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);
    }

    public function test_submit_refuses_already_valid_invoice(): void
    {
        // The ETL-cutover safety: imported legacy invoices have
        // mydata_state='VALID' and a real MARK. Clicking Submit on
        // one of them must NOT re-file at AADE.
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_state' => 'VALID',
            'mydata_mark' => '400099999999999',
            'mydata_sent' => true,
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed at myDATA/');

        (new MyDataSubmitter($this->tenant))->submit($invoice->fresh());
    }

    public function test_submit_refuses_draft_invoice_without_code(): void
    {
        // code=0 means InvoiceNumberer never ran. Filing with <aa>0</aa>
        // would either be rejected with an opaque AADE error OR worse
        // accepted as a real (illegal) filing.
        $invoice = $this->makeInvoice(code: 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no ΑΑ number/');

        // Use previewXml to bypass the AADE call but still exercise
        // the build path — we want the draft guard to throw BEFORE
        // any AADE POST or DB write.
        (new MyDataSubmitter($this->tenant))->previewXml($invoice);
    }

    public function test_cancel_refuses_already_cancelled(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_state' => 'CANCELLED',
            'mydata_mark' => '400088888888888',
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already cancelled/');

        (new MyDataSubmitter($this->tenant))->cancel($invoice->fresh());
    }

    public function test_cancel_refuses_when_no_insert_mark_in_history(): void
    {
        // No mydata_marks row exists for the invoice. cancel() should
        // refuse rather than guess (the mirror column may have stale
        // data from a corrupted ETL import).
        $invoice = $this->makeInvoice();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no INSERT MARK on file/');

        (new MyDataSubmitter($this->tenant))->cancel($invoice->fresh());
    }

    public function test_preview_xml_works_on_off_mode_tenant_without_credentials(): void
    {
        // Dry-run must work without credentials — the whole point of
        // Off mode is "no AADE call". previewXml() should never
        // initFirebed() or require credentials.
        $tenantWithoutCreds = Company::create([
            'name' => 'Cred-less',
            'slug' => 'no-cred-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'afm' => '800561849',
            // sandbox + production credential slots all null
        ]);

        $type = InvoiceType::create([
            'company_id' => $tenantWithoutCreds->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);
        $cust = Customer::create([
            'company_id' => $tenantWithoutCreds->id,
            'name' => 'Test',
            'afm' => '987654321',
        ]);
        $vat = VatCategory::create([
            'company_id' => $tenantWithoutCreds->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        $inv = Invoice::create([
            'company_id' => $tenantWithoutCreds->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $cust->id,
            'issued_at' => now(),
        ]);
        InvoiceLine::create([
            'company_id' => $tenantWithoutCreds->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $mark = (new MyDataSubmitter($tenantWithoutCreds))->previewXml($inv);

        $this->assertSame('DRY_RUN', $mark->mydata_action);
        $this->assertNull($mark->mark);
        $this->assertStringContainsString('<invoice', $mark->request);
        // Invoice mirror columns must be UNTOUCHED.
        $this->assertNull($inv->fresh()->mydata_state);
        $this->assertNull($inv->fresh()->mydata_mark);
    }

    public function test_retail_invoice_type_does_not_attach_counterpart_even_with_afm(): void
    {
        // 11.x types FORBID Counterpart. Even when the customer has
        // an AFM (legitimately, e.g. business customer paying cash),
        // an 11.2 ΑΠΥ submission must omit Counterpart.
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'APY',
            'name' => 'ΑΠΥ',
            'invcount' => 1,
            'mydata_type' => '11.2',  // retail receipt
        ]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'APY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id,  // customer HAS afm
            'issued_at' => now(),
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $mark = (new MyDataSubmitter($this->tenant))->previewXml($inv);
        $xml = $mark->request;

        // No <counterpart> in the payload — branch on type kicks in
        // before the customer's AFM matters.
        $this->assertStringNotContainsString('<counterpart', $xml);
    }

    public function test_b2b_invoice_type_without_customer_afm_throws(): void
    {
        // Symmetric: non-retail types REQUIRE Counterpart, which
        // requires a customer with AFM. Without one, throw with a
        // clear message instead of silently filing without Counterpart
        // (which AADE would reject opaquely).
        $cashCustomer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Cash buyer',
            // no afm
        ]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY42',
            'code' => 42,
            'invoice_type_id' => $this->invoiceType->id,  // 1.1 B2B
            'customer_id' => $cashCustomer->id,
            'issued_at' => now(),
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a customer with AFM/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv);
    }

    public function test_zero_percent_vat_throws_when_no_exemption_category_configured(): void
    {
        // G4: a 0% line is now fileable as vatCategory=7 — BUT only if the
        // tenant's 0%-rate VAT category carries an exemption reason. Without
        // one, the submitter still refuses (rather than file a blank reason).
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '0% (no reason)',
            'rate' => 0,
            'is_default' => false,
        ]);
        $inv = $this->makeInvoice();
        $this->zeroVatLine($inv);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no 0%-rate VAT category has a vat_exemption_category/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv);
    }

    public function test_zero_percent_vat_files_as_category_7_with_exemption_reason(): void
    {
        // G4: with the exemption reason configured, the 0% line files as
        // vatCategory=7 + the vatExemptionCategory code AADE requires.
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => 'Ενδοκοινοτική παράδοση',
            'rate' => 0,
            'vat_exemption_category' => 5,
            'is_default' => false,
        ]);
        $inv = $this->makeInvoice();
        $this->zeroVatLine($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv)->request;

        $this->assertStringContainsString('<vatCategory>7</vatCategory>', $xml);
        $this->assertStringContainsString('<vatExemptionCategory>5</vatExemptionCategory>', $xml);
    }

    public function test_zero_percent_vat_throws_when_exemption_reason_ambiguous(): void
    {
        // Two 0%-rate categories with different reasons → the line can't say
        // which, so refuse rather than guess.
        foreach ([5, 12] as $i => $code) {
            VatCategory::create([
                'company_id' => $this->tenant->id,
                'description' => '0% #'.$i,
                'rate' => 0,
                'vat_exemption_category' => $code,
                'is_default' => false,
            ]);
        }
        $inv = $this->makeInvoice();
        $this->zeroVatLine($inv);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/different exemption reasons/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv);
    }

    public function test_withholding_emits_taxestotals_block(): void
    {
        // G1: an invoice carrying a withholding amount + category files a
        // taxesTotals[taxType=1] block with the amount AADE can account for.
        $inv = $this->makeInvoice();
        $this->standardLine($inv);
        $inv->forceFill(['withhold_amount' => 200, 'withhold_category' => 3])->save();

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<taxesTotals>', $xml);
        $this->assertStringContainsString('<taxType>1</taxType>', $xml);
        $this->assertStringContainsString('<taxAmount>200', $xml);
        $this->assertStringContainsString('<totalWithheldAmount>200', $xml);
    }

    public function test_withholding_amount_without_category_throws(): void
    {
        $inv = $this->makeInvoice();
        $this->standardLine($inv);
        $inv->forceFill(['withhold_amount' => 200, 'withhold_category' => null])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no valid withholding category/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'));
    }

    public function test_standard_invoice_emits_no_taxestotals(): void
    {
        // Regression: the sandbox-validated path (no withholding) must NOT
        // gain a taxesTotals block.
        $inv = $this->makeInvoice();
        $this->standardLine($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringNotContainsString('<taxesTotals>', $xml);
    }

    public function test_payment_method_type_defaults_to_cash_when_unmapped(): void
    {
        // G9 regression: no payment method (or unmapped) → type 3 (cash),
        // the prior hardcoded behaviour, now the documented default.
        $inv = $this->makeInvoice();
        $this->standardLine($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<type>3</type>', $xml);
    }

    public function test_payment_method_type_comes_from_the_mapped_method(): void
    {
        // G9: a method mapped to §8.12 type 7 (POS/e-POS) files as type 7.
        $pm = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'description' => 'Κάρτα',
            'due_days' => 0,
            'mydata_payment_type' => 7,
        ]);
        $inv = $this->makeInvoice();
        $inv->forceFill(['payment_method_id' => $pm->id])->save();
        $this->standardLine($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<type>7</type>', $xml);
        $this->assertStringNotContainsString('<type>3</type>', $xml);
    }

    public function test_service_type_emits_no_per_line_quantity(): void
    {
        // G5 regression: the validated service path (mydata_requires_quantity
        // off) must NOT send <quantity> ([205] forbids it).
        $inv = $this->makeInvoice();
        $this->standardLine($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringNotContainsString('<quantity>', $xml);
    }

    public function test_goods_type_emits_per_line_quantity(): void
    {
        // G5: a goods-flagged invoice type sends the per-line quantity.
        $goods = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'GDS',
            'name' => 'Τιμολόγιο αγαθών',
            'invcount' => 1,
            'mydata_type' => '1.1',
            'mydata_requires_quantity' => true,
        ]);
        $inv = $this->makeInvoice();
        $inv->forceFill(['invoice_type_id' => $goods->id])->save();
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 5,
            'vat_percent' => 24,
            'net_price' => 1000,
            'gross_price' => 1240,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<quantity>5</quantity>', $xml);
    }

    private function zeroVatLine(Invoice $inv): void
    {
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 0,
            'net_price' => 100,
            'gross_price' => 100,
        ]);
    }

    private function standardLine(Invoice $inv): void
    {
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 1000,
            'gross_price' => 1240,
        ]);
    }

    public function test_submit_refuses_already_cancelled_invoice(): void
    {
        // Symmetric to the already-VALID guard. Refile of a cancelled
        // invoice would corrupt the audit trail and the local mirror
        // would disagree with AADE.
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_state' => 'CANCELLED',
            'mydata_mark' => '400088888888888',
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/previously filed and CANCELLED/');

        (new MyDataSubmitter($this->tenant))->submit($invoice->fresh());
    }

    public function test_submit_refuses_unknown_mydata_state_value(): void
    {
        // Belt-and-suspenders catch-all from the fourth review. The
        // VALID/CANCELLED guards are exhaustive for legacy data, but
        // any future or corrupted state value should also refuse
        // rather than silently treat as "never filed" and double-file.
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_state' => 'PENDING',  // not VALID, not CANCELLED, not null
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unrecognised mydata_state/');

        (new MyDataSubmitter($this->tenant))->submit($invoice->fresh());
    }

    public function test_foreign_counterpart_normalises_country_and_carries_name_address(): void
    {
        // Operators have been observed typing full country names.
        // The submitter must (a) normalise to ISO alpha-2 before
        // AADE, and (b) include `name` + `address` for non-GR
        // counterparts (AADE rejects missing fields with opaque errors).
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPYEU',
            'name' => 'EU sale',
            'invcount' => 1,
            'mydata_type' => '1.2',
        ]);
        $cust = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'ACME GmbH',
            'afm' => 'DE123456789',
            'country' => 'Germany',  // free-text — normalisation must catch
        ]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPYEU1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $cust->id,
            'issued_at' => now(),
            'country' => 'Germany',  // snapshot in same free-text shape
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $mark = (new MyDataSubmitter($this->tenant))->previewXml($inv);

        // Must serialise as DE, not "Germany" — AADE rejects the latter.
        $this->assertStringContainsString('<country>DE</country>', $mark->request);
        // Foreign counterpart MUST carry name + address — AADE
        // rejects missing fields with opaque errors.
        $this->assertStringContainsString('ACME GmbH', $mark->request);
        $this->assertStringContainsString('<address>', $mark->request);
    }

    public function test_build_counterpart_throws_on_unknown_country_string(): void
    {
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPYWT',
            'name' => 'Wakanda sale',
            'invcount' => 1,
            'mydata_type' => '1.3',
        ]);
        $cust = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Wakanda Corp',
            'afm' => 'WK000001',
            'country' => 'Wakanda',
        ]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPYWT1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $cust->id,
            'issued_at' => now(),
            'country' => 'Wakanda',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/normalise country/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv);
    }

    public function test_preview_xml_omits_client_uid(): void
    {
        // The payload must NOT carry a client-supplied <uid>. A live
        // AADE sandbox filing (2026-05-28) rejected it with "[273] uid is
        // not allowed. It is generated/provided by myDATA", and the
        // legacy accepted payload sent no uid either. AADE derives its
        // own deterministic uid (VAT + date + branch + type + series +
        // AA) and uses THAT for retry dedup, so idempotency still holds
        // server-side without us sending guessUid().
        $inv = $this->makeInvoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $mark = (new MyDataSubmitter($this->tenant))->previewXml($inv);

        $this->assertStringNotContainsString('<uid>', $mark->request, 'AADE forbids a client-supplied <uid> ([273])');
    }

    public function test_non_correlated_credit_5_2_omits_correlation(): void
    {
        // A 5.2 (non-correlated) credit note must NOT carry
        // <correlatedInvoices> even though it has a credited_invoice_id —
        // AADE forbids the correlation for this type. Because we skip it,
        // originalInsertMark() is never called, so no INSERT MARK row is
        // needed for the original (and it must not throw).
        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'PIS',
            'name' => 'Πιστωτικό',
            'invcount' => 1,
            'mydata_type' => '5.2',
            'is_credit' => true,
        ]);

        $original = $this->makeInvoice(code: 10);

        $credit = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'PIS1',
            'code' => 1,
            'invoice_type_id' => $creditType->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
            'credited_invoice_id' => $original->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $credit->id,
            'qty' => 1,
            'vat_percent' => 24,
            'net_price' => 100,
            'gross_price' => 124,
        ]);

        $mark = (new MyDataSubmitter($this->tenant))->previewXml($credit);

        $this->assertStringNotContainsString(
            '<correlatedInvoices>',
            $mark->request,
            'AADE forbids <correlatedInvoices> on a 5.2 non-correlated credit note',
        );
    }

    public function test_rejected_submission_throws_mydatarejected_and_records_forensic_row(): void
    {
        $invoice = $this->makeInvoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'qty' => 1,
            'price_per_item' => 10,
            'vat_percent' => 24,
            'product_descr' => 'Δοκιμή',
        ]);

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->validationErrorXml())]);

        try {
            (new MyDataSubmitter($this->tenant, $mock))->submit($invoice->fresh('lines'));
            $this->fail('expected MyDataRejected on a non-Success response');
        } catch (MyDataRejected $e) {
            $this->assertStringContainsString('myDATA rejected', $e->getMessage());
            $this->assertStringContainsString('<invoiceType>', $e->requestXml);  // the request we sent
            $this->assertStringContainsString('ValidationError', $e->responseXml); // the AADE refusal
        }

        // A forensic REJECTED row is persisted (visible in the invoice myDATA
        // «Ιστορικό»), carrying the response so the operator sees WHY.
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'REJECTED',
            'mark' => null,
        ]);
        $this->assertNull($invoice->fresh()->mydata_state, 'a rejected submission must NOT mark the invoice VALID');
    }

    public function test_rejected_cancellation_does_not_flip_state_and_records_forensic_row(): void
    {
        // Regression for the 2026-06-05 incident: AADE returned HTTP 200 +
        // ValidationError [301] ("mark not found") to a CancelInvoice, yet the
        // invoice was flipped to CANCELLED locally (and "cancelled" pushed to
        // WHMCS) because the cancel path didn't check the response status.
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_state' => 'VALID',
            'local_status' => 'active',
            'mydata_mark' => '400013829677137',
        ])->save();
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '400013829677137',
            'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->cancelNotFoundXml())]);

        try {
            (new MyDataSubmitter($this->tenant, $mock))->cancel($invoice->fresh(), 'Τεστ');
            $this->fail('expected RuntimeException on a non-Success cancel response');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rejected the cancellation', $e->getMessage());
        }

        // State MUST be untouched — AADE never cancelled anything.
        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state, 'a rejected cancel must NOT flip mydata_state');
        $this->assertSame('active', $fresh->local_status, 'a rejected cancel must NOT flip local_status');

        // A forensic CANCEL_REJECTED row exists; NO successful CANCEL row.
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'CANCEL_REJECTED',
            'mark' => null,
        ]);
        $this->assertDatabaseMissing('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'CANCEL',
        ]);
    }

    public function test_successful_cancellation_flips_state_and_stores_cancellation_mark(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_state' => 'VALID',
            'local_status' => 'active',
            'mydata_mark' => '400013829677137',
        ])->save();
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '400013829677137',
            'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->cancelSuccessXml())]);

        (new MyDataSubmitter($this->tenant, $mock))->cancel($invoice->fresh(), 'Δοκιμή');

        $fresh = $invoice->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('cancelled', $fresh->local_status);

        // The CANCEL row keeps the original MARK and now also the cancellation mark.
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'CANCEL',
            'mark' => '400013829677137',
            'cancellation_mark' => '400099999999999',
        ]);
    }

    private function cancelSuccessXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <statusCode>Success</statusCode>
        <cancellationMark>400099999999999</cancellationMark>
    </response>
</ResponseDoc>
XML;
    }

    private function cancelNotFoundXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <statusCode>ValidationError</statusCode>
        <errors>
            <error>
                <message>Invoice with ΜΑΡΚ 400013829677137 not found for VAT number 800561849</message>
                <code>301</code>
            </error>
        </errors>
    </response>
</ResponseDoc>
XML;
    }

    private function validationErrorXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <statusCode>ValidationError</statusCode>
        <errors>
            <error>
                <message>Test validation error</message>
                <code>205</code>
            </error>
        </errors>
    </response>
</ResponseDoc>
XML;
    }

    private function makeInvoice(int $code = 1): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY'.$code,
            'code' => $code,
            'invoice_type_id' => $this->invoiceType->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
        ]);
    }
}
