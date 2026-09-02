<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\MyDataRejected;
use App\Services\MyDataSubmitter;
use App\Support\Afm;
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

    public function test_submit_files_and_persists_the_mark_from_a_successful_response(): void
    {
        // Full SendInvoices round-trip against a MOCKED AADE success response —
        // the integration coverage the submitter lacked (previewXml only tested
        // request-building; the other tests cover refusal guards). Uses firebed's
        // own success stub so the parsed MARK + qrUrl persist path runs end-to-end.
        $inv = $this->makeInvoice();
        $this->standardLine($inv);

        $xml = file_get_contents(base_path('vendor/firebed/aade-mydata/stubs/send-invoices-single-response.xml'));
        $mock = new MockHandler([new GuzzleResponse(200, [], $xml)]);

        $mark = (new MyDataSubmitter($this->tenant, $mock))->submit($inv->fresh('lines'));

        // Returned mark row.
        $this->assertSame('480301204040191', $mark->mark);
        $this->assertSame('INSERT', $mark->mydata_action);

        // Invoice cache flipped to VALID with the MARK + qrUrl.
        $inv->refresh();
        $this->assertSame('VALID', $inv->mydata_state);
        $this->assertSame('480301204040191', $inv->mydata_mark);
        $this->assertNotEmpty($inv->mydata_url);

        // Audit row persisted.
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $inv->id,
            'mark' => '480301204040191',
            'mydata_action' => 'INSERT',
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

    public function test_movement_only_type_cannot_be_filed_as_a_monetary_invoice(): void
    {
        // MYD-003: a 9.x (Δελτίο Αποστολής) must never reach the monetary builder —
        // it belongs to the Delivery Notes flow. Guards CLI/API/imported callers
        // that bypass the UI picker.
        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $this->invoiceType->forceFill(['mydata_type' => '9.3'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/movement-only/');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
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
        $this->expectExceptionMessageMatches('/requires a counterpart ΑΦΜ/');

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

    public function test_four_percent_rate_defaults_to_category_6(): void
    {
        // No override configured → the rate-derived §8.2 code (4% → 6, islands).
        $inv = $this->makeInvoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 4, 'net_price' => 100, 'gross_price' => 104,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<vatCategory>6</vatCategory>', $xml);
    }

    public function test_four_percent_rate_uses_the_configured_override(): void
    {
        // ν.5057/2023 regime: the tenant's 4% VatCategory pins category 10.
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '4% ν.5057',
            'rate' => 4, 'mydata_vat_category' => 10,
        ]);
        $inv = $this->makeInvoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 4, 'net_price' => 100, 'gross_price' => 104,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<vatCategory>10</vatCategory>', $xml);
        $this->assertStringNotContainsString('<vatCategory>6</vatCategory>', $xml);
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

    public function test_all_additional_tax_types_emit_blocks_and_totals(): void
    {
        // #3c: fees (2, §8.7) / otherTaxes (3, §8.5) / digital transaction fee
        // (4, §8.6) / deductions (5) each emit a taxesTotals block carrying its
        // taxType AND taxCategory, + set the matching summary total.
        $inv = $this->makeInvoice();
        $this->standardLine($inv);
        $inv->forceFill([
            'fees_amount' => 30, 'fees_category' => 1,
            'other_taxes_amount' => 20, 'other_taxes_category' => 1,
            'stamp_duty_amount' => 50, 'stamp_duty_category' => 2,   // §8.6 category 2 = 2.4%
            'deductions_amount' => 10, 'deductions_category' => 1,
        ])->save();

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        // Each block pairs the right taxType with its category (order: taxType → taxCategory).
        $this->assertMatchesRegularExpression('#<taxType>2</taxType>\s*<taxCategory>1</taxCategory>#', $xml);
        $this->assertMatchesRegularExpression('#<taxType>3</taxType>\s*<taxCategory>1</taxCategory>#', $xml);
        $this->assertMatchesRegularExpression('#<taxType>4</taxType>\s*<taxCategory>2</taxCategory>#', $xml);
        $this->assertMatchesRegularExpression('#<taxType>5</taxType>\s*<taxCategory>1</taxCategory>#', $xml);
        $this->assertStringContainsString('<totalFeesAmount>30', $xml);
        $this->assertStringContainsString('<totalOtherTaxesAmount>20', $xml);
        $this->assertStringContainsString('<totalStampDutyAmount>50', $xml);   // legacy tag = digital transaction fee
        $this->assertStringContainsString('<totalDeductionsAmount>10', $xml);
    }

    public function test_additional_taxes_adjust_gross_and_payment(): void
    {
        // gross = net+vat + fees + stamp + otherTaxes − deductions (firebed's
        // getTotalTaxes, withheld excluded); the paymentMethod amount must match it.
        $inv = $this->makeInvoice();
        InvoiceLine::create([ // net 1000, gross 1240 (price_per_item drives the computed net)
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24,
        ]);
        $inv->forceFill([
            'fees_amount' => 30, 'fees_category' => 1,
            'stamp_duty_amount' => 50, 'stamp_duty_category' => 1,
            'other_taxes_amount' => 20, 'other_taxes_category' => 1,
            'deductions_amount' => 10, 'deductions_category' => 1,
        ])->save();

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        // 1240 + 30 + 50 + 20 − 10 = 1330
        $this->assertStringContainsString('<totalGrossValue>1330', $xml);
        $this->assertStringContainsString('<amount>1330', $xml);
    }

    public function test_withholding_reduces_gross_for_an_affecting_category(): void
    {
        // Withholding category 3 («Αμοιβές Συμβούλων 20%») DOES affect gross: AADE's
        // [208] reconciliation requires totalGrossValue = net+vat − withheld. Filing
        // gross=1240 with withheld=200 unsubtracted was sandbox-REJECTED [208] on
        // 2026-06-10; the correct gross is 1040 (and the payment amount must match).
        $inv = $this->makeInvoice();
        InvoiceLine::create([ // net 1000, gross 1240
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24,
        ]);
        $inv->forceFill(['withhold_amount' => 200, 'withhold_category' => 3])->save();

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<totalWithheldAmount>200', $xml);
        $this->assertStringContainsString('<totalGrossValue>1040', $xml);
        $this->assertStringContainsString('<amount>1040', $xml);
    }

    public function test_informational_withholding_does_not_change_gross(): void
    {
        // The "informational" prepaid-tax categories §8.4 8/9/10 (architects /
        // engineers / lawyers) are reported but do NOT reduce gross — firebed's
        // WithheldPercentCategory::affectsTotalGrossValue() is false only for them,
        // so gross stays net+vat. (Untested on the AADE sandbox — only cat 3 was
        // round-tripped 2026-06-10; revisit if a tenant files an 8/9/10 withholding.)
        $inv = $this->makeInvoice();
        InvoiceLine::create([ // net 1000, gross 1240
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24,
        ]);
        $inv->forceFill(['withhold_amount' => 200, 'withhold_category' => 9])->save();

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<totalWithheldAmount>200', $xml);
        $this->assertStringContainsString('<totalGrossValue>1240', $xml);
        $this->assertStringContainsString('<amount>1240', $xml);
    }

    public function test_vat_category_override_is_ignored_on_a_non_ambiguous_rate(): void
    {
        // A 24% category with a mis-set override (e.g. bad ETL) must NOT hijack the
        // unambiguous 24% → 1 mapping.
        $this->vat->forceFill(['mydata_vat_category' => 6])->save();
        $inv = $this->makeInvoice();
        $this->standardLine($inv); // 24%

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<vatCategory>1</vatCategory>', $xml);
        $this->assertStringNotContainsString('<vatCategory>6</vatCategory>', $xml);
    }

    public function test_additional_tax_amount_without_category_throws(): void
    {
        $inv = $this->makeInvoice();
        $this->standardLine($inv);
        $inv->forceFill(['fees_amount' => 30, 'fees_category' => null])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no valid category/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'));
    }

    public function test_invalid_additional_tax_category_throws(): void
    {
        // 999 is not a valid §8.7 fees category → loud-fail, don't file garbage.
        $inv = $this->makeInvoice();
        $this->standardLine($inv);
        $inv->forceFill(['fees_amount' => 30, 'fees_category' => 999])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no valid category/');

        (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'));
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

    public function test_set_but_unmapped_payment_method_files_as_cash_without_blocking(): void
    {
        // MYD-4: a chosen method with no §8.12 mapping falls back to cash (3) and
        // does NOT block the filing (a live tenant must not be halted over a
        // payload-quality issue — the config audit surfaces the gap instead).
        $pm = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Κάρτα', 'due_days' => 0,
            // mydata_payment_type deliberately null
        ]);
        $inv = $this->makeInvoice();
        $inv->forceFill(['payment_method_id' => $pm->id])->save();
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

    public function test_header_discount_folds_into_per_line_values(): void
    {
        // MYD-1 regression: lines store net/gross WITHOUT the header discount,
        // the summary applies it — so the payload used to ship
        // Σ(line netValue) ≠ totalNetValue and AADE rejected with [207]/[209].
        // A 10% header discount on a 100/124 line must now file the DISCOUNTED
        // line values (90 / 21.60), matching the summary.
        $inv = $this->makeInvoice();
        $inv->forceFill(['header_discount_percent' => 10])->save();
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'price_per_item' => 100,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<netValue>90</netValue>', $xml);
        $this->assertStringContainsString('<vatAmount>21.6</vatAmount>', $xml);
        $this->assertStringContainsString('<totalNetValue>90</totalNetValue>', $xml);
        $this->assertStringContainsString('<totalVatAmount>21.6</totalVatAmount>', $xml);
    }

    public function test_header_discount_line_sums_match_summary_totals_exactly(): void
    {
        // MYD-1: the awkward-rounding case. Three 33.33 lines at 24% plus a
        // 13% line, 10% header discount: each discounted line rounds to 30.00
        // but the per-rate breakdown target is 89.99, so a cent must be taken
        // from one line. Whatever the allocation, the [207]/[209] invariants
        // are what AADE checks: Σ(line netValue) == totalNetValue and
        // Σ(line vatAmount) == totalVatAmount, to the cent.
        $inv = $this->makeInvoice();
        $inv->forceFill(['header_discount_percent' => 10])->save();
        foreach ([1, 2, 3] as $i) {
            InvoiceLine::create([
                'company_id' => $this->tenant->id,
                'invoice_id' => $inv->id,
                'qty' => 1,
                'vat_percent' => 24,
                'price_per_item' => 33.33,
            ]);
        }
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 13,
            'price_per_item' => 50,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        [$lineNetSum, $lineVatSum] = $this->sumLineValues($xml);
        $this->assertSame($this->summaryValue($xml, 'totalNetValue'), $lineNetSum, '[207] Σ(line netValue) must equal totalNetValue');
        $this->assertSame($this->summaryValue($xml, 'totalVatAmount'), $lineVatSum, '[209] Σ(line vatAmount) must equal totalVatAmount');

        // And the totals themselves are the header-discounted breakdown values:
        // net (99.99 + 50) × 0.9 per-rate-rounded = 89.99 + 45.00 = 134.99.
        $this->assertSame(134.99, $this->summaryValue($xml, 'totalNetValue'));
    }

    public function test_header_discount_income_classifications_sum_to_summary(): void
    {
        // MYD-1: per-line E3 classification amounts must also carry the
        // discounted net, or the summary classification (built from the
        // discounted total) wouldn't match the lines.
        $this->invoiceType->forceFill([
            'mydata_income_class' => 'E3_561_001',
            'mydata_income_class_category' => 'category1_1',
        ])->save();

        $inv = $this->makeInvoice();
        $inv->forceFill(['header_discount_percent' => 10])->save();
        foreach ([1, 2, 3] as $i) {
            InvoiceLine::create([
                'company_id' => $this->tenant->id,
                'invoice_id' => $inv->id,
                'qty' => 1,
                'vat_percent' => 24,
                'price_per_item' => 33.33,
            ]);
        }

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $perLine = 0.0;
        $summaryAmount = null;
        foreach ($doc->getElementsByTagNameNS('*', 'incomeClassification') as $node) {
            $amount = (float) $node->getElementsByTagNameNS('*', 'amount')->item(0)?->textContent;
            if ($node->parentNode?->localName === 'invoiceSummary') {
                $summaryAmount = $amount;
            } else {
                $perLine += $amount;
            }
        }
        $this->assertNotNull($summaryAmount);
        $this->assertSame($summaryAmount, round($perLine, 2), 'Σ(per-line classification) must equal the summary classification');
        $this->assertSame(89.99, $summaryAmount);
    }

    public function test_mixed_category_invoice_files_per_line_income_class(): void
    {
        // MYD-5: a product's CATEGORY overrides the goods/services BUCKET per line,
        // while the E3 TYPE keeps coming from the invoice type (channel-driven).
        // So a mixed invoice files goods lines under category1_1 and service lines
        // under category1_3 — both under the type's E3_561_001 — and the summary
        // emits one node per distinct (type, category), summing to totalNet.
        $this->invoiceType->forceFill([
            'mydata_income_class' => 'E3_561_001',
            'mydata_income_class_category' => 'category1_1',
        ])->save();

        // The recommended pattern: set ONLY the bucket on the category.
        $goods = ProductCategory::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Εμπορεύματα',
            'mydata_income_class_category' => 'category1_1',
        ]);
        $services = ProductCategory::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Υπηρεσίες',
            'mydata_income_class_category' => 'category1_3',
        ]);

        $goodsProduct = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Server',
            'product_category_id' => $goods->id, 'vat_category_id' => $this->vat->id,
        ]);
        $serviceProduct = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Support',
            'product_category_id' => $services->id, 'vat_category_id' => $this->vat->id,
        ]);

        $inv = $this->makeInvoice();
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'product_id' => $goodsProduct->id, 'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'product_id' => $serviceProduct->id, 'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 40,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $doc = new \DOMDocument;
        $doc->loadXML($xml);

        // Summary: one node per distinct (type|category), summing to net. Both lines
        // keep the type's E3_561_001; the buckets differ (goods 1_1 vs services 1_3).
        $summary = [];
        foreach ($doc->getElementsByTagNameNS('*', 'incomeClassification') as $node) {
            if ($node->parentNode?->localName !== 'invoiceSummary') {
                continue;
            }
            $type = $node->getElementsByTagNameNS('*', 'classificationType')->item(0)?->textContent;
            $cat = $node->getElementsByTagNameNS('*', 'classificationCategory')->item(0)?->textContent;
            $amount = (float) $node->getElementsByTagNameNS('*', 'amount')->item(0)?->textContent;
            $summary["{$type}|{$cat}"] = ($summary["{$type}|{$cat}"] ?? 0) + $amount;
        }

        $this->assertSame([
            'E3_561_001|category1_1' => 100.0,
            'E3_561_001|category1_3' => 40.0,
        ], $summary);
        $this->assertSame(140.0, $this->summaryValue($xml, 'totalNetValue'));
    }

    private function lineOn(Invoice $inv, float $price = 100): void
    {
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => $price,
        ]);
    }

    public function test_greek_customer_with_el_vat_prefix_country_files_as_gr(): void
    {
        // MYD-6 review F3: 'EL' (the EU VAT prefix for Greece) must normalise to GR
        // and NOT hard-block a domestic 1.1 filing on the country↔type cross-check.
        $this->customer->forceFill(['country' => 'EL'])->save();
        $inv = $this->makeInvoice(); // type 1.1
        $this->lineOn($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<country>GR</country>', $xml);
    }

    public function test_domestic_type_with_foreign_counterpart_is_rejected(): void
    {
        // MYD-6: type 1.1 with a non-GR counterpart → clear error instead of [242].
        $this->customer->forceFill(['country' => 'DE'])->save();
        $inv = $this->makeInvoice();
        $this->lineOn($inv);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/απαιτεί αντισυμβαλλόμενο/u');
        (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'));
    }

    public function test_intracommunity_type_with_gr_counterpart_is_rejected(): void
    {
        // MYD-6: type 1.2 with a GR counterpart → clear error instead of [243].
        $this->invoiceType->forceFill(['mydata_type' => '1.2'])->save();
        $inv = $this->makeInvoice(); // customer defaults to GR
        $this->lineOn($inv);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/απαιτεί αντισυμβαλλόμενο/u');
        (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'));
    }

    public function test_foreign_counterpart_without_address_is_rejected_not_faked(): void
    {
        // MYD-6: a foreign counterpart with no address hard-fails instead of
        // filing 'Unknown'/'00000' placeholders.
        $this->invoiceType->forceFill(['mydata_type' => '1.2'])->save();
        $this->customer->forceFill(['country' => 'DE', 'address1' => null, 'city' => null, 'postcode' => null])->save();
        $inv = $this->makeInvoice();
        $this->lineOn($inv);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a full address');
        (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'));
    }

    public function test_foreign_counterpart_with_full_address_files_the_real_address(): void
    {
        $this->invoiceType->forceFill(['mydata_type' => '1.2'])->save();
        $this->customer->forceFill([
            'country' => 'DE', 'address1' => 'Hauptstrasse 1', 'city' => 'Berlin', 'postcode' => '10115',
        ])->save();
        $inv = $this->makeInvoice();
        $this->lineOn($inv);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('Hauptstrasse 1', $xml);
        $this->assertStringContainsString('Berlin', $xml);
        $this->assertStringNotContainsString('Unknown', $xml);
        $this->assertStringNotContainsString('00000', $xml);
    }

    public function test_no_header_discount_keeps_raw_line_values(): void
    {
        // Regression for the sandbox-validated shape: with no header discount
        // the allocation is the identity — raw stored line values file as-is.
        $inv = $this->makeInvoice();
        InvoiceLine::create([ // net 1000 / gross 1240 (computed by the saving hook), hd = 0
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'qty' => 1,
            'vat_percent' => 24,
            'price_per_item' => 1000,
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($inv->fresh('lines'))->request;

        $this->assertStringContainsString('<netValue>1000</netValue>', $xml);
        $this->assertStringContainsString('<vatAmount>240</vatAmount>', $xml);
        $this->assertStringContainsString('<totalNetValue>1000</totalNetValue>', $xml);
    }

    /** Σ(netValue), Σ(vatAmount) across the payload's invoiceDetails elements. */
    private function sumLineValues(string $xml): array
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $net = 0.0;
        $vat = 0.0;
        foreach ($doc->getElementsByTagNameNS('*', 'invoiceDetails') as $detail) {
            $net += (float) $detail->getElementsByTagNameNS('*', 'netValue')->item(0)?->textContent;
            $vat += (float) $detail->getElementsByTagNameNS('*', 'vatAmount')->item(0)?->textContent;
        }

        return [round($net, 2), round($vat, 2)];
    }

    private function summaryValue(string $xml, string $tag): float
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);

        return (float) $doc->getElementsByTagNameNS('*', $tag)->item(0)?->textContent;
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

    public function test_submit_refuses_locally_cancelled_invoice(): void
    {
        // MYD-3 (AUDIT): a locally-voided sale must never be filed — it would
        // declare income at AADE that the ledger doesn't recognise. Guarded at
        // service level so bulk/console/automation callers are covered too.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['local_status' => 'cancelled'])->save();
        $this->standardLine($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/locally cancelled/');

        (new MyDataSubmitter($this->tenant))->submit($invoice->fresh('lines'));
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
            // MYD-6: a foreign counterpart now needs a REAL address (no placeholders).
            'address1' => 'Hauptstrasse 1',
            'city' => 'Berlin',
            'postcode' => '10115',
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

    public function test_correlated_credit_5_1_resolves_a_direct_insert_mark(): void
    {
        // Baseline: a 5.1 credit correlates to a directly-filed original via its
        // INSERT MARK (regression guard for MYD-008).
        $original = $this->makeInvoice(code: 10);
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $original->id,
            'mark' => '400000000000111',
            'mydata_action' => 'INSERT',
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($this->makeCorrelatedCredit($original, 1))->request;

        $this->assertStringContainsString('<correlatedInvoices>', $xml);
        $this->assertStringContainsString('400000000000111', $xml);
    }

    public function test_correlated_credit_5_1_resolves_a_provider_insert_mark(): void
    {
        // MYD-008: a 5.1 credit against a PROVIDER-issued original must correlate via
        // its PROVIDER_INSERT MARK, exactly like a direct INSERT — the resolver reads
        // both actions so a provider-issued document stays correctable.
        $original = $this->makeInvoice(code: 20);
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $original->id,
            'mark' => '400000000000222',
            'mydata_action' => 'PROVIDER_INSERT',
        ]);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($this->makeCorrelatedCredit($original, 2))->request;

        $this->assertStringContainsString('<correlatedInvoices>', $xml);
        $this->assertStringContainsString('400000000000222', $xml);
    }

    public function test_correlated_credit_5_1_ignores_a_rejected_provider_attempt(): void
    {
        // A failed/rejected provider attempt (PROVIDER_REJECTED, null mark) must NEVER
        // be accepted as the original MARK (MYD-008 acceptance).
        $original = $this->makeInvoice(code: 30);
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $original->id,
            'mark' => null,
            'mydata_action' => 'PROVIDER_REJECTED',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#INSERT/PROVIDER_INSERT MARK#');

        (new MyDataSubmitter($this->tenant))->previewXml($this->makeCorrelatedCredit($original, 3));
    }

    public function test_correlated_credit_5_1_refuses_a_cross_tenant_original(): void
    {
        // Same-tenant enforcement (MYD-008): a credited_invoice_id pointing at another
        // tenant's invoice must not resolve its MARK — the CompanyScope is a no-op
        // off-request, so the explicit company filter is what blocks the leak.
        $other = Company::create([
            'name' => 'Other co', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '999888777',
        ]);
        $otherType = InvoiceType::create([
            'company_id' => $other->id, 'code' => 'TPY', 'name' => 'x', 'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $otherCustomer = Customer::create(['company_id' => $other->id, 'name' => 'x', 'afm' => '111222333']);
        $foreign = Invoice::create([
            'company_id' => $other->id, 'invcode' => 'TPY99', 'code' => 99,
            'invoice_type_id' => $otherType->id, 'customer_id' => $otherCustomer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $other->id, 'invoice_id' => $foreign->id,
            'mark' => '400000000000999', 'mydata_action' => 'INSERT',
        ]);

        // The credit is created in $this->tenant but points at the foreign original.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing original invoice/');

        (new MyDataSubmitter($this->tenant))->previewXml($this->makeCorrelatedCredit($foreign, 4));
    }

    public function test_correlated_credit_5_1_ignores_a_foreign_company_mark_row(): void
    {
        // Tenant isolation on the MARK row itself (MYD-008 review): the original is
        // in our tenant, but an inconsistent audit row belonging to ANOTHER company
        // points at the same invoice_id. invoice_id alone would find it; the explicit
        // company_id filter must exclude it, leaving no usable MARK.
        $other = Company::create([
            'name' => 'Other co', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '555444333',
        ]);
        $original = $this->makeInvoice(code: 50);
        MyDataMark::create([
            'company_id' => $other->id,             // ← belongs to the WRONG tenant
            'invoice_id' => $original->id,          // ← but points at our original
            'mark' => '400000000000555',
            'mydata_action' => 'PROVIDER_INSERT',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#INSERT/PROVIDER_INSERT MARK#');

        (new MyDataSubmitter($this->tenant))->previewXml($this->makeCorrelatedCredit($original, 6));
    }

    public function test_correlated_credit_5_1_refuses_a_non_numeric_mark(): void
    {
        // Contract guard (MYD-008 review): the resolver feeds addCorrelatedInvoice((int)…),
        // so a non-numeric MARK must fail loudly, never be silently truncated to a wrong doc.
        $original = $this->makeInvoice(code: 40);
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $original->id,
            'mark' => 'NOT-A-NUMBER',
            'mydata_action' => 'PROVIDER_INSERT',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-numeric filing MARK/');

        (new MyDataSubmitter($this->tenant))->previewXml($this->makeCorrelatedCredit($original, 5));
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

    public function test_cancel_self_heals_when_aade_reports_already_cancelled(): void
    {
        // MYD-7: a prior cancel succeeded at AADE but the local write failed, so
        // the invoice still reads VALID locally. The retry gets [251] "already
        // cancelled" — which must SYNC local state to CANCELLED (self-heal), not
        // throw forever.
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

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->cancelAlreadyCancelledXml())]);

        // Does NOT throw — it heals.
        (new MyDataSubmitter($this->tenant, $mock))->cancel($invoice->fresh(), 'Δοκιμή');

        $fresh = $invoice->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state, '[251] must sync local state to CANCELLED');
        $this->assertSame('cancelled', $fresh->local_status);

        // A CANCEL audit row is written (no cancellation mark — AADE did no new cancel).
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'CANCEL',
            'mark' => '400013829677137',
            'cancellation_mark' => null,
        ]);
    }

    private function cancelAlreadyCancelledXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <statusCode>ValidationError</statusCode>
        <errors>
            <error>
                <message>Invoice with MARK 400013829677137 cannot be cancelled because of being already cancelled</message>
                <code>251</code>
            </error>
        </errors>
    </response>
</ResponseDoc>
XML;
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

    /* ============ MYD-009: the counterpart is the FROZEN snapshot ============ */

    public function test_editing_the_customer_after_issue_does_not_change_the_filed_identity(): void
    {
        // THE headline acceptance. buildCounterpart() used to file $customer->afm
        // and $customer->name while taking country/address from the snapshot, so a
        // customer edit today rewrote the reported party of an invoice filed a year
        // ago — and assembled ONE reported party out of TWO real ones.
        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => '123456789',
            'company_name' => 'Πελάτης ΑΕ',
            'country' => 'GR',
        ])->save();

        $before = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        // The customer is renamed and re-registered under a different ΑΦΜ.
        $this->customer->forceFill([
            'afm' => '094014201',
            'name' => 'Μετονομασμένος ΑΕ',
            'country' => 'DE',
        ])->save();

        $after = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertSame($before, $after, 'a customer edit must not change a filed document');
        $this->assertStringContainsString('<vatNumber>123456789</vatNumber>', $after);
        $this->assertStringNotContainsString('094014201', $after);
        $this->assertStringNotContainsString('Μετονομασμένος', $after);
    }

    public function test_a_filed_invoice_never_reads_the_live_customer(): void
    {
        // Once filed, the snapshot is the ONLY source: mydata_sent closes the
        // legacy fallback, so a blank column can no longer be filled in from a
        // customer row that has moved on since.
        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => '', 'company_name' => '', 'country' => '',
            'mydata_sent' => true, 'mydata_mark' => '400000000000777',
        ])->save();

        $this->assertTrue($invoice->hasBeenFiled());
        $this->assertNull($invoice->counterpartAfm());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed/');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
    }

    public function test_a_party_typed_over_a_customer_link_does_not_borrow_its_country(): void
    {
        // The invoice form leaves customer_id in place while the party fields are
        // overtyped, so the link can describe somebody else entirely. Borrowing that
        // customer's country would assemble one reported party out of two.
        $this->customer->forceFill(['country' => 'GR', 'afm' => '123456789'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => 'DE811234567',        // a different, foreign party
            'company_name' => 'Müller GmbH',
            'country' => null,                 // must NOT resolve to the customer's GR
        ])->save();

        $this->assertFalse($invoice->fresh()->counterpartIsTheLinkedCustomer());
        $this->assertNull($invoice->fresh()->counterpartCountryIso());
    }

    public function test_a_blank_snapshot_is_frozen_at_the_moment_of_filing(): void
    {
        // Legacy/ETL rows arrive with a blank snapshot and resolve from the customer.
        // The same write sets mydata_sent, which CLOSES that fallback — so unless the
        // resolved party is frozen here, what we reported becomes unreadable.
        $this->customer->forceFill(['afm' => '123456789', 'name' => 'Πελάτης ΑΕ', 'country' => 'ΙΤΑΛΙΑ'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => null, 'company_name' => null, 'country' => null])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertSame('123456789', $frozen['vat_no']);
        $this->assertSame('Πελάτης ΑΕ', $frozen['company_name']);
        // Stored NORMALISED — the column is an ISO-2 record of what was filed.
        $this->assertSame('IT', $frozen['country']);
    }

    public function test_the_freeze_never_overwrites_a_value_the_document_carries(): void
    {
        $this->customer->forceFill(['afm' => '800561849', 'name' => 'Άλλος ΑΕ'])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill(['vat_no' => '123456789', 'company_name' => 'Πελάτης ΑΕ', 'country' => 'GR'])->save();

        $this->assertSame([], $invoice->fresh()->frozenPartyColumns());
    }

    public function test_a_foreign_party_with_a_closed_fallback_is_refused_not_filed_as_gr(): void
    {
        // ROUND-1 P0. The country chain re-implemented the fallback and then applied
        // «blank → GR» to the result of a CLOSED one, so "no country evidence" and
        // "there is a country but this document may not read it" collapsed into the
        // same answer. An Italian party whose customer link had drifted was filed as
        // GR with no name and no address — where the old code loudly refused.
        $this->customer->forceFill(['country' => 'IT', 'name' => 'Bella Italia SRL'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => 'IT12345678901',
            'company_name' => 'Bella Italia S.R.L.',   // drifted from the customer
            'country' => null,
        ])->save();

        $this->assertFalse($invoice->fresh()->counterpartIsTheLinkedCustomer());

        try {
            $xml = (string) (new MyDataSubmitter($this->tenant))
                ->previewXml($invoice->fresh('lines'))->request;
            $this->fail('Expected a refusal, filed: '.$xml);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('records no counterpart country', $e->getMessage());
            // The ΑΦΜ itself is the evidence here, so THAT is the reason reported.
            $this->assertStringContainsString('IT VAT identifier', $e->getMessage());
        }
    }

    public function test_a_foreign_vat_prefix_is_evidence_even_with_no_customer_at_all(): void
    {
        // ROUND-2 P0. The "nothing recorded anywhere" branch tested
        // blank($customer?->country), which is ALSO true when the customer is
        // soft-deleted or absent — so an «IT…» party with a deleted customer was
        // filed as GR with no name and no address. The ΑΦΜ carries the country.
        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'customer_id' => null,
            'vat_no' => 'IT12345678901',
            'company_name' => 'Bella Italia SRL',
            'country' => null,
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/IT VAT identifier/');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
    }

    public function test_a_drifted_customer_link_reports_the_drift_as_the_reason(): void
    {
        // Same refusal, different evidence: a BARE-digit ΑΦΜ says nothing about the
        // country, so the reason is that the linked customer describes someone else.
        $this->customer->forceFill(['country' => 'IT', 'name' => 'Άλλος ΑΕ'])->save();

        $invoice = $this->makeInvoice(code: 55);
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => '997073525',
            'company_name' => 'Πελάτης ΑΕ',   // drifted from the customer
            'country' => null,
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/different party/');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
    }

    public function test_no_country_evidence_anywhere_still_defaults_to_gr(): void
    {
        // The other side of the same coin: the GR default must survive for the legacy
        // domestic population, or every pre-existing invoice becomes unissuable.
        $this->customer->forceFill(['country' => null])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => '123456789', 'company_name' => 'Πελάτης ΑΕ', 'country' => null])->save();

        $xml = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertStringContainsString('<country>GR</country>', $xml);
    }

    public function test_the_filed_vat_number_is_canonical_not_raw_free_text(): void
    {
        // invoices.vat_no is a bare TextInput and an ETL copy of the legacy column,
        // so it holds «IT 12345678901» / «EL123456789». Filing it verbatim earns an
        // opaque AADE rejection; the previous code filed customers.afm, which the
        // form and GSIS/VIES keep clean.
        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => 'EL 123.456.789', 'company_name' => 'Πελάτης ΑΕ', 'country' => 'GR'])->save();

        $xml = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertStringContainsString('<vatNumber>123456789</vatNumber>', $xml);
    }

    public function test_the_freeze_covers_the_address_that_was_actually_filed(): void
    {
        // Freezing only ΑΦΜ/name/country left a FILED foreign document unable to
        // reproduce its own counterpart: the payload carried street/city/postcode
        // from the live customer, and re-rendering afterwards threw "requires a full
        // address" while AADE held the real one.
        $this->customer->forceFill([
            'afm' => 'IT12345678901', 'name' => 'Bella Italia SRL', 'country' => 'IT',
            'address1' => 'Via Roma 1', 'city' => 'Milano', 'postcode' => '20100',
        ])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'vat_no' => null, 'company_name' => null, 'country' => null,
            'address1' => null, 'city' => null, 'postcode' => null,
        ])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertSame('IT', $frozen['country']);
        $this->assertSame('Via Roma 1', $frozen['address1']);
        $this->assertSame('Milano', $frozen['city']);
        $this->assertSame('20100', $frozen['postcode']);
    }

    public function test_the_freeze_truncates_to_the_column_width(): void
    {
        // customers.name is varchar(191), invoices.company_name varchar(120), and
        // MySQL runs strict — an over-long copy would raise INSIDE the transaction
        // that writes the MARK audit row, rolling back a filing AADE already
        // accepted and leaving the invoice permanently stuck.
        // The column is widened to 191 to match customers.name, so a real name now
        // survives intact; the truncation stays as a guard against any future
        // widening of the SOURCE. Both properties are asserted.
        $long = str_repeat('Α', 191);
        $this->customer->forceFill(['name' => $long])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill(['vat_no' => null, 'company_name' => null, 'country' => 'GR'])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertSame(191, mb_strlen($frozen['company_name']));
        $this->assertLessThanOrEqual(191, mb_strlen($frozen['company_name']));
    }

    public function test_a_retail_receipt_freezes_no_party_at_all(): void
    {
        // 11.x files NO counterpart, so stamping one into the "what we reported"
        // columns would assert a party AADE was never told about — and the PDF keys
        // its counterpart block on vat_no, so it would start printing it.
        $retail = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'APY', 'name' => 'Απόδειξη',
            'invcount' => 1, 'mydata_type' => '11.1',
        ]);

        $invoice = $this->makeInvoice(code: 77);
        $invoice->forceFill([
            'invoice_type_id' => $retail->id,
            'vat_no' => null, 'company_name' => null, 'country' => null,
        ])->save();

        $this->assertTrue($invoice->fresh()->filesNoCounterpart());
        $this->assertSame([], $invoice->fresh()->frozenPartyColumns());
    }

    public function test_the_sanctioned_gr_default_is_frozen_like_any_other(): void
    {
        // ROUND-2 P1. The GR default lived only in the builder, so it was filed but
        // never recorded. An operator later filling customers.country — a routine
        // edit — then made the ALREADY FILED invoice un-renderable, because the
        // fallback was closed and the document had no country of its own.
        $this->customer->forceFill(['country' => null])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill(['vat_no' => '997073525', 'company_name' => 'Πελάτης ΑΕ', 'country' => null])->save();

        $this->assertSame('GR', $invoice->fresh()->frozenPartyColumns()['country']);
    }

    public function test_the_address_is_frozen_when_only_the_address_is_blank(): void
    {
        // ROUND-2 P1. The freeze gate only inspected the IDENTITY columns, so a
        // foreign invoice with a complete identity but a blank address filed the
        // customer's street/city/postcode and froze nothing — and then threw
        // "requires a full address" on re-render while AADE held the real one.
        $this->customer->forceFill([
            'address1' => 'Via Roma 1', 'city' => 'Milano', 'postcode' => '20100',
            'name' => 'Bella Italia SRL', 'afm' => 'IT12345678901', 'country' => 'IT',
        ])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'vat_no' => 'IT12345678901',
            'company_name' => 'Bella Italia SRL',
            'country' => 'IT',
            'address1' => null, 'city' => null, 'postcode' => null,
        ])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertSame('Via Roma 1', $frozen['address1']);
        $this->assertSame('Milano', $frozen['city']);
        $this->assertSame('20100', $frozen['postcode']);
    }

    public function test_an_unresolvable_snapshot_country_is_not_replaced_by_the_customers(): void
    {
        // ROUND-2 P2. counterpartCountryIso() fell through to the customer, so a
        // snapshot reading «Germania» was quietly overwritten by the customer's GR —
        // the recorded-value-is-evidence rule broken again.
        $this->customer->forceFill(['country' => 'GR'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => '997073525', 'company_name' => 'Πελάτης ΑΕ', 'country' => 'Germania'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Germania/');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
    }

    public function test_an_el_prefixed_afm_is_the_same_party_as_the_bare_one(): void
    {
        // ROUND-2 P2. canonicalVat stripped the EL prefix on the way out while
        // comparisonKey kept it, so one commit called the same taxpayer two
        // different parties and refused an invoice that used to file. customers.afm
        // legitimately carries the prefix (the VIES form-fill seeds a full VAT id).
        $this->customer->forceFill(['afm' => 'EL997073525', 'country' => 'GR'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => '997073525', 'company_name' => null, 'country' => null])->save();

        $this->assertTrue($invoice->fresh()->counterpartIsTheLinkedCustomer());

        $xml = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertStringContainsString('<vatNumber>997073525</vatNumber>', $xml);
    }

    public function test_every_eu_vat_shape_counts_as_foreign_evidence_not_just_the_digits_only_ones(): void
    {
        // ROUND-3 P0. The prefix test matched «two letters then digits», but a real
        // EU VAT id is rarely that shape — ATU12345678, CY12345678L, NL123456789B01,
        // IE1234567FA, ESX1234567X. Half of Europe fell through and was filed as GR
        // with no name and no address; the IT case (digits-only) was the only one the
        // tests covered.
        $shapes = [
            'ATU12345678' => 'AT',
            'CY12345678L' => 'CY',
            'NL123456789B01' => 'NL',
            'IE1234567FA' => 'IE',
            'ESX1234567X' => 'ES',
            'FRK7399859412' => 'FR',
        ];

        $n = 300;
        foreach ($shapes as $vat => $expected) {
            $invoice = $this->makeInvoice(code: $n++);
            $this->standardLine($invoice);
            $invoice->forceFill([
                'customer_id' => null,
                'vat_no' => $vat,
                'company_name' => 'Foreign Co',
                'country' => null,
            ])->save();

            try {
                $xml = (string) (new MyDataSubmitter($this->tenant))
                    ->previewXml($invoice->fresh('lines'))->request;
                $this->fail("{$vat} was filed instead of refused: ".$xml);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("{$expected} VAT identifier", $e->getMessage());
            }
        }
    }

    public function test_a_renamed_customer_does_not_make_a_legacy_invoice_unissuable(): void
    {
        // ROUND-3 P1. The identity check treated a NAME difference as fatal even when
        // the ΑΦΜ matched exactly — so a routine customer rename made every legacy
        // invoice with a blank country unissuable (and its credit notes with it). The
        // ΑΦΜ is the identity; a name change is a rename, not a different taxpayer.
        $this->customer->forceFill(['afm' => '997073525', 'country' => 'GR', 'name' => 'Πελάτης ΑΕ (νέα επωνυμία)'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => '997073525',
            'company_name' => 'Πελάτης ΑΕ',   // the name as it was at issue
            'country' => null,
        ])->save();

        $this->assertTrue($invoice->fresh()->counterpartIsTheLinkedCustomer());

        $xml = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertStringContainsString('<country>GR</country>', $xml);
    }

    public function test_a_domestic_counterpart_freezes_its_address_for_the_provider_document(): void
    {
        // AADE omits the address for a GR party, but the PROVIDER document carries it
        // and so does our PDF — so a GR invoice that froze no address could not
        // reproduce its own provider payload once filed.
        $this->customer->forceFill([
            'afm' => '997073525', 'country' => 'GR',
            'address1' => 'Πατησίων 1', 'city' => 'Αθήνα', 'postcode' => '11111',
        ])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'vat_no' => '997073525', 'company_name' => 'Πελάτης ΑΕ', 'country' => 'GR',
            'address1' => null, 'city' => null, 'postcode' => null,
        ])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertSame('Πατησίων 1', $frozen['address1']);
        $this->assertSame('Αθήνα', $frozen['city']);
    }

    public function test_a_bare_greek_afm_with_no_country_anywhere_files_as_gr(): void
    {
        // The ordinary Greek/WHMCS shape: a plain nine-digit ΑΦΜ, no «EL» prefix, and
        // no country recorded on either side. It must file as GR without any guard
        // getting in the way — every tightening in this issue has to keep this true.
        $this->customer->forceFill(['afm' => '997073525', 'country' => null])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => '997073525', 'company_name' => 'Πελάτης ΑΕ', 'country' => null])->save();

        $xml = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertStringContainsString('<vatNumber>997073525</vatNumber>', $xml);
        $this->assertStringContainsString('<country>GR</country>', $xml);
    }

    public function test_free_text_in_the_vat_column_is_not_read_as_a_country_claim(): void
    {
        // ROUND-4 P2. vat_no is an unvalidated TextInput and a raw ETL copy, so it
        // holds things like «INV-2024-01» — which the first prefix matcher read as
        // India and refused. A VAT body carries at least seven digits (Ireland is the
        // shortest); junk falls under that floor.
        foreach (['INV-2024-01', 'ID 044123', 'VAT123', 'LTD 12'] as $junk) {
            $this->assertNull(Afm::countryPrefix($junk), "«{$junk}» must not claim a country");
        }

        $this->assertSame('IE', Afm::countryPrefix('IE1234567FA'));
    }

    public function test_a_recorded_country_wins_over_a_disagreeing_vat_prefix(): void
    {
        // Round 4 threw on this disagreement as a "coherence" check; round 5 removed
        // it, because the two legitimately differ — Monaco files under an FR VAT id,
        // the Isle of Man under GB, Northern Ireland under XI — and the recorded
        // country is the operator's explicit statement while the prefix is an
        // inference from a free-text column. Refusing blocked correct documents and
        // told the operator to fix a value that was already right.
        // MC is a third country, so the document must be a 1.3 and carry the foreign
        // counterpart's name + address that AADE requires.
        $this->invoiceType->forceFill(['mydata_type' => '1.3'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => 'FR12345678901',
            'company_name' => 'Monaco SARL',
            'country' => 'MC',
            'address1' => 'Rue Grimaldi 1', 'city' => 'Monaco', 'postcode' => '98000',
        ])->save();

        $xml = (string) (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'))->request;

        $this->assertStringContainsString('<country>MC</country>', $xml);
        $this->assertStringContainsString('Monaco SARL', $xml);
    }

    public function test_a_greek_vat_prefix_answers_the_country_question(): void
    {
        // With nothing recorded, a GR/EL prefix IS the answer. Falling through to the
        // refusal discarded positive evidence the document already carried.
        $this->customer->forceFill(['country' => null, 'name' => 'Άλλος ΑΕ'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill([
            'vat_no' => 'EL12345678',      // not 9 digits, so the prefix survives
            'company_name' => 'Πελάτης ΑΕ',
            'country' => null,
        ])->save();

        $this->assertSame('GR', $invoice->fresh()->counterpartCountryForFiling());
    }

    public function test_a_domestic_afm_with_stray_letters_is_not_a_country_claim(): void
    {
        // «AE997073525» is a real nine-digit ΑΦΜ with two stray letters. Accepting any
        // ISO-2 code as a VAT prefix read it as the UAE; only prefixes actually used
        // in front of a VAT id count.
        foreach (['AE997073525', 'SA997073525', 'MO997073525', 'INV20240001'] as $value) {
            $this->assertNull(Afm::countryPrefix($value), "«{$value}» must not claim a country");
        }

        $this->assertSame('RO', Afm::countryPrefix('RO361902'));
    }

    public function test_a_punctuation_only_afm_does_not_pass_two_parties_as_one(): void
    {
        // ROUND-4 P2. «-» trims non-empty but canonicalises to nothing, and an empty
        // key equals a null customer ΑΦΜ — so the name check was skipped entirely and
        // a different party's country/address could be borrowed.
        $this->customer->forceFill(['afm' => null, 'name' => 'ΠΕΛΑΤΗΣ ΜΟΥ ΑΕ', 'country' => 'IT'])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill(['vat_no' => '-', 'company_name' => 'ΑΛΛΟΣ ΠΕΛΑΤΗΣ ΑΕ', 'country' => null])->save();

        $this->assertFalse($invoice->fresh()->counterpartIsTheLinkedCustomer());
        $this->assertNull($invoice->fresh()->counterpartCountryIso());
    }

    public function test_an_unresolvable_customer_country_says_so_instead_of_blaming_a_party_mismatch(): void
    {
        // ROUND-6 P2. Three reasons reach that refusal; only two were offered, so an
        // unresolvable customer country was reported as a party mismatch that did not
        // exist — and unlike the code it replaced, the offending value was not named.
        $this->customer->forceFill(['afm' => '997073525', 'name' => 'Πελάτης ΑΕ', 'country' => 'ΗΝ. ΒΑΣΙΛΕΙΟ'])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill(['vat_no' => '997073525', 'company_name' => 'Πελάτης ΑΕ', 'country' => null])->save();

        $this->assertTrue($invoice->fresh()->counterpartIsTheLinkedCustomer());

        try {
            $invoice->fresh()->counterpartCountryForFiling();
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ΗΝ. ΒΑΣΙΛΕΙΟ', $e->getMessage());
            $this->assertStringNotContainsString('different party', $e->getMessage());
        }
    }

    public function test_an_all_zeros_vat_no_is_a_placeholder_not_an_identity(): void
    {
        // ROUND-6 observation. «0» / «000000000» mean "no ΑΦΜ" — the same convention
        // the delivery-note sentinel uses — so they must fall through to the customer
        // rather than become the reported party (and close its country fallback).
        $this->customer->forceFill(['afm' => '997073525', 'country' => 'GR'])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill(['vat_no' => '0', 'company_name' => null, 'country' => null])->save();

        $this->assertSame('997073525', $invoice->fresh()->counterpartAfm());
        $this->assertSame('GR', $invoice->fresh()->counterpartCountryForFiling());
    }

    public function test_northern_ireland_vat_resolves_to_gb(): void
    {
        // ROUND-6 P2. «XI» is a VAT jurisdiction, not an ISO country, so IsoCountry
        // never knew it and the allowlist entry was inert — the round-5 commit cited
        // XI as a reason while the code never produced that prefix at all.
        $this->assertSame('GB', Afm::countryPrefix('XI123456789'));
    }

    public function test_a_placeholder_vat_no_is_replaced_by_what_was_actually_filed(): void
    {
        // ROUND-7 P1, and a defect the round-6 fix created: the freeze gates on
        // blank(), but «000000000» is not blank — while canonicalVat() now reads it as
        // "no ΑΦΜ". So the payload filed the customer's real ΑΦΜ and the column kept
        // the placeholder: a half-frozen legal identity, manufactured by MYD-009's own
        // freeze. The PDF would print one party while AADE held another, and the row
        // was unrecoverable (a filed invoice is not editable, credit notes copy it).
        $this->customer->forceFill(['afm' => '997073525', 'name' => 'Πελάτης ΑΕ', 'country' => 'GR'])->save();

        foreach (['000000000', '0'] as $i => $placeholder) {
            $invoice = $this->makeInvoice(code: 900 + $i);
            $invoice->forceFill([
                'vat_no' => $placeholder,
                'company_name' => null,
                'country' => null,
            ])->save();

            $frozen = $invoice->fresh()->frozenPartyColumns();

            $this->assertSame('997073525', $frozen['vat_no'] ?? null, "«{$placeholder}» must be replaced");
        }
    }

    public function test_a_placeholder_customer_afm_is_not_reported_as_borrowable(): void
    {
        // ROUND-7 P2: the arm tested raw filled(), so a customer whose ΑΦΜ is itself a
        // placeholder was described as having one to borrow.
        $this->customer->forceFill(['afm' => '000000000', 'name' => 'Άλλος ΑΕ'])->save();

        $invoice = $this->makeInvoice();
        $this->standardLine($invoice);
        $invoice->forceFill(['vat_no' => null, 'company_name' => 'Πελάτης ΑΕ', 'country' => 'GR'])->save();

        try {
            (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('linked customer has one', $e->getMessage());
        }
    }

    public function test_a_zero_string_address_column_is_frozen_like_a_blank_one(): void
    {
        // ROUND-8 P2-A. Both the AADE builder and the provider document select the
        // address with `?:`, which treats the string «0» as absent — while blank()
        // does not. So the payload filed the customer's postcode and the freeze kept
        // the «0», leaving the filed document unable to render its own counterpart.
        $this->customer->forceFill([
            'afm' => 'DE811234567', 'name' => 'Lieferant GmbH', 'country' => 'DE',
            'address1' => 'Hauptstr 1', 'city' => 'Berlin', 'postcode' => '10115',
        ])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'vat_no' => 'DE811234567', 'company_name' => 'Lieferant GmbH', 'country' => 'DE',
            'address1' => null, 'city' => null, 'postcode' => '0',
        ])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertSame('10115', $frozen['postcode'] ?? null, '«0» must freeze like a blank');
    }

    public function test_a_whitespace_address_column_freezes_like_the_selector_sees_it(): void
    {
        // ROUND-9 P2. `?:` is falsy for '' and '0' but NOT for ' ', so trimming in the
        // gate froze the customer's real street while the provider payload carried the
        // blank one — document and snapshot disagreeing, which is what the freeze
        // exists to prevent.
        $this->customer->forceFill(['afm' => '997073525', 'country' => 'GR', 'address1' => 'Ermou 5'])->save();

        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'vat_no' => '997073525', 'company_name' => 'Πελάτης ΑΕ', 'country' => 'GR',
            'address1' => ' ',
        ])->save();

        $frozen = $invoice->fresh()->frozenPartyColumns();

        $this->assertArrayNotHasKey('address1', $frozen, 'a space is what the selector files, so freeze nothing');
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

    /**
     * A 5.1 (correlated) credit note against $original, in $this->tenant. The 5.1
     * type is shared across calls; the credited_invoice_id drives correlation.
     */
    private function makeCorrelatedCredit(Invoice $original, int $code): Invoice
    {
        $creditType = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'PIS'],
            ['name' => 'Πιστωτικό Συσχ.', 'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true],
        );

        $credit = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'PIS'.$code,
            'code' => $code,
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

        return $credit->fresh('lines');
    }
}
