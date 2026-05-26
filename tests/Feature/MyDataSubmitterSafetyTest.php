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
            'mydata_aade_id' => 'TESTUSER',
            'mydata_subscription_key' => 'TESTKEY',
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
            // mydata_aade_id + mydata_subscription_key both null
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

    public function test_zero_percent_vat_throws_instead_of_misclassifying(): void
    {
        // 0% lines need a vatExemptionCategory we don't yet capture.
        // Silent mapping to category 7 was the bug; throwing is the
        // explicit refusal.
        $vatZero = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '0% (placeholder)',
            'rate' => 0,
            'is_default' => false,
        ]);
        $inv = $this->makeInvoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 0,
            'net_price' => 100,
            'gross_price' => 100,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/0% VAT line without an exemption category/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv);
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

    public function test_buildCounterpart_throws_on_unknown_country_string(): void
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

    public function test_preview_xml_sets_uid_for_idempotency(): void
    {
        // UID must appear in the payload so AADE dedupes retries.
        // The two-line invoice issued today should always produce
        // the same UID (deterministic).
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

        // Look for <uid> in the request XML — firebed serialises the
        // UID in that element.
        $this->assertStringContainsString('<uid>', $mark->request, 'Payload must include <uid> for AADE-side dedup');
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
