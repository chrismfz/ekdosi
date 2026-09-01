<?php

namespace Tests\Feature\EInvoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\EInvoice\Transports\InvoSignDocument;
use App\Services\EInvoice\Transports\InvoSignTransport;
use App\Services\EInvoiceSubmitterFactory;
use App\Support\EInvoice\ProviderCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * P5: the InvoSign transport — builds the xml_arxeio (AADE InvoicesDoc + InvoSign
 * extension), POSTs it, and parses the ResponseDoc into a ProviderResult. HTTP is
 * faked (no network, no real creds). The end-to-end test drives the full chain
 * factory → GrProviderSubmitter → InvoSignTransport → parse → PROVIDER_INSERT.
 */
class InvoSignTransportTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO = 'https://demo.invosign.test';

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'ΓΕΩΡΓΑΚΟΠΟΥΛΟΣ ΟΕ', 'slug' => 'invo-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
            'tax_office' => 'ΚΕΦΟΔΕ', 'address' => 'ΑΔΡΙΑΝΟΥ 16', 'city' => 'ΑΘΗΝΑ', 'postcode' => '14121',
            'einvoice_provider_config' => ['demo_base_url' => self::DEMO, 'demo_token' => 'DEMO-TOKEN'],
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '997073525',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);
    }

    public function test_document_augments_aade_xml_with_extension(): void
    {
        $invoice = $this->makeInvoice();
        $aade = (new AadeInvoiceDocument($this->tenant))->toXml((new AadeInvoiceDocument($this->tenant))->build($invoice));

        $xml = InvoSignDocument::augment($aade, $invoice);

        // AADE core preserved + InvoSign extension appended.
        $this->assertStringContainsString('<invoiceDetails>', $xml);
        $this->assertStringContainsString('api_lineDescription', $xml);
        $this->assertStringContainsString('Υπηρεσία', $xml);
        $this->assertStringContainsString('API_InvoiceDetails', $xml);
        $this->assertStringContainsString('<IssuerName>ΓΕΩΡΓΑΚΟΠΟΥΛΟΣ ΟΕ</IssuerName>', $xml);
        $this->assertStringContainsString('<CounterpartVat>997073525</CounterpartVat>', $xml);

        // [88-004]: InvoSign needs the n1/n2 classification prefixes (firebed emits
        // icls/ecls). After augment the InvoSign payload must use n1/n2 only.
        $this->assertStringContainsString('xmlns:n1=', $xml);
        $this->assertStringContainsString('<n1:classificationType>', $xml);
        $this->assertStringNotContainsString('icls:', $xml);
        $this->assertStringNotContainsString('xmlns:icls', $xml);

        // Still valid XML.
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_send_success_parses_mark_and_uses_sandbox_creds(): void
    {
        Http::fake([self::DEMO.'/*' => Http::response($this->successXml(), 200)]);
        $invoice = $this->makeInvoice();

        $result = (new InvoSignTransport)->send($invoice, '<InvoicesDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"><invoice></invoice></InvoicesDoc>', ProviderCredentials::fromCompany($this->tenant));

        $this->assertTrue($result->success);
        $this->assertSame('400001957061986', $result->mark);
        $this->assertSame('AUTH-XYZ', $result->authenticationCode);
        $this->assertSame('https://invosign.gr/viewinvoice.php?uid=UID1', $result->qrUrl);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/iNVOSign_Api.php')
                && $request['token'] === 'DEMO-TOKEN'
                && str_contains((string) $request['xml_arxeio'], 'API_InvoiceDetails');
        });
    }

    public function test_send_refuses_a_non_https_base_url_before_any_request(): void
    {
        // A copied/typo http:// endpoint must never receive the token + XML (PROV-017).
        $this->tenant->forceFill([
            'einvoice_provider_config' => ['demo_base_url' => 'http://demo.invosign.test', 'demo_token' => 'DEMO-TOKEN'],
        ])->save();
        $invoice = $this->makeInvoice();

        Http::fake();

        try {
            (new InvoSignTransport)->send($invoice, '<InvoicesDoc/>', ProviderCredentials::fromCompany($this->tenant->fresh()));
            $this->fail('Expected a rejection for an http base URL.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('URL', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_send_refuses_a_private_host_base_url_before_any_request(): void
    {
        // An internal/loopback endpoint (SSRF) must be blocked before the POST.
        $this->tenant->forceFill([
            'einvoice_provider_config' => ['demo_base_url' => 'https://127.0.0.1', 'demo_token' => 'DEMO-TOKEN'],
        ])->save();
        $invoice = $this->makeInvoice();

        Http::fake();

        try {
            (new InvoSignTransport)->send($invoice, '<InvoicesDoc/>', ProviderCredentials::fromCompany($this->tenant->fresh()));
            $this->fail('Expected a rejection for a private-host base URL.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('URL', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_send_delivery_augments_with_api_invoice_details_and_normalises_prefixes(): void
    {
        Http::fake([self::DEMO.'/*' => Http::response($this->successXml(), 200)]);
        $note = new DeliveryNote([
            'company_id' => $this->tenant->id,
            'invcode' => 'ΔΑΠ1',
            'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789',
            'delivery_city' => 'Θεσσαλονίκη',
        ]);
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<InvoicesDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0" xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0">'
            .'<invoice><invoiceDetails><icls:incomeClassification><icls:classificationType>category3</icls:classificationType></icls:incomeClassification></invoiceDetails></invoice></InvoicesDoc>';

        $result = (new InvoSignTransport)->sendDelivery($note, $xml, ProviderCredentials::fromCompany($this->tenant));

        $this->assertTrue($result->success);

        // [88-006]: the mandatory <API_InvoiceDetails> (issuer + recipient as
        // counterpart) is now appended for delivery notes too.
        $payload = (string) $result->requestPayload;
        $this->assertStringContainsString('API_InvoiceDetails', $payload);
        $this->assertStringContainsString('<IssuerName>ΓΕΩΡΓΑΚΟΠΟΥΛΟΣ ΟΕ</IssuerName>', $payload);
        $this->assertStringContainsString('<CounterpartName>Παραλήπτης ΑΕ</CounterpartName>', $payload);
        $this->assertStringContainsString('<CounterpartVat>123456789</CounterpartVat>', $payload);
        // Delivery-specific Additionals from the provider's own ΔΑ example.
        $this->assertStringContainsString('<DocumentDispatchTo>Θεσσαλονίκη</DocumentDispatchTo>', $payload);

        // [88-004]: prefix normalisation still applied, and still valid XML.
        $this->assertStringContainsString('xmlns:n1=', $payload);
        $this->assertStringContainsString('<n1:classificationType>', $payload);
        $this->assertStringNotContainsString('icls:', $payload);
        $this->assertNotFalse(simplexml_load_string($payload));

        Http::assertSent(fn ($request) => str_contains((string) $request['xml_arxeio'], 'API_InvoiceDetails')
            && str_contains((string) $request['xml_arxeio'], 'xmlns:n1=')
            && ! str_contains((string) $request['xml_arxeio'], 'icls:'));
    }

    public function test_send_delivery_endodiakinisi_fills_counterpart_fallback_and_per_line_api_fields(): void
    {
        // Sandbox-found (2026-06-09): for an ενδοδιακίνηση (no external recipient)
        // InvoSign rejects an empty <CounterpartName> ([88-001]) AND a missing
        // per-line <api_lineDescription> ([88-001]). Lock both fixes.
        Http::fake([self::DEMO.'/*' => Http::response($this->successXml(), 200)]);

        $deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'DA', 'name' => 'ΔΑ',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id, 'invcode' => 'DA1', 'code' => 1,
            'delivery_type_id' => $deliveryType->id, 'issued_at' => now(), 'mydata_type' => '9.3',
            'move_purpose' => 8, 'local_status' => 'draft', // NO recipient → ενδοδιακίνηση
        ]);
        $note->lines()->create([
            'company_id' => $this->tenant->id, 'qty' => 2, 'measurement_unit' => 1,
            'product_descr' => 'Κιβώτια δοκιμής',
        ]);

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<InvoicesDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0" xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0">'
            .'<invoice><invoiceDetails><lineNumber>1</lineNumber><itemDescr>Κιβώτια δοκιμής</itemDescr>'
            .'<icls:incomeClassification><icls:classificationType>category3</icls:classificationType></icls:incomeClassification>'
            .'</invoiceDetails></invoice></InvoicesDoc>';

        $payload = (string) (new InvoSignTransport)
            ->sendDelivery($note->fresh('lines'), $xml, ProviderCredentials::fromCompany($this->tenant))
            ->requestPayload;

        // [88-001] CounterpartName/Vat fall back to the issuer (it IS the recipient) + 000000000.
        $this->assertStringContainsString('<CounterpartName>ΓΕΩΡΓΑΚΟΠΟΥΛΟΣ ΟΕ</CounterpartName>', $payload);
        $this->assertStringContainsString('<CounterpartVat>000000000</CounterpartVat>', $payload);
        // [88-001] per-line api_* twins present (monetary fields 0.00 for a delivery line).
        $this->assertStringContainsString('<api_lineDescription>Κιβώτια δοκιμής</api_lineDescription>', $payload);
        $this->assertStringContainsString('<api_quantity>2.0000</api_quantity>', $payload);
        $this->assertStringContainsString('<api_NetPriceBeforeDiscount>0.00</api_NetPriceBeforeDiscount>', $payload);
    }

    public function test_send_validation_error_becomes_failed_result(): void
    {
        Http::fake([self::DEMO.'/*' => Http::response($this->errorXml(), 200)]);
        $invoice = $this->makeInvoice();

        $result = (new InvoSignTransport)->send($invoice, '<InvoicesDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"><invoice></invoice></InvoicesDoc>', ProviderCredentials::fromCompany($this->tenant));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('[238]', $result->errorMessage());
    }

    public function test_success_without_mark_is_treated_as_failure_and_does_not_file(): void
    {
        // B1: a 'Success' with an empty <invoiceMark> must NOT mark the invoice VALID.
        $xml = '<?xml version="1.0" encoding="utf-8"?><ResponseDoc><response>'
            .'<invoiceMark></invoiceMark><statusCode>Success</statusCode></response></ResponseDoc>';
        Http::fake([self::DEMO.'/*' => Http::response($xml, 200)]);
        $invoice = $this->makeInvoice();

        $submitter = app(EInvoiceSubmitterFactory::class)->for($this->tenant->fresh());

        try {
            $submitter->submit($invoice);
            $this->fail('Expected a failure for Success-without-MARK.');
        } catch (\Throwable $e) {
            // MyDataRejected (transport returned failed) — either way, NOT filed.
        }

        $this->assertNull($invoice->fresh()->mydata_state);
        $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
    }

    public function test_http_error_throws_and_does_not_file(): void
    {
        // A 500 (and the recovery status-check also 500) → throw, invoice stays unfiled.
        Http::fake([self::DEMO.'/*' => Http::response('upstream boom', 500)]);
        $invoice = $this->makeInvoice();

        $submitter = app(EInvoiceSubmitterFactory::class)->for($this->tenant->fresh());

        try {
            $submitter->submit($invoice);
            $this->fail('Expected a transport failure.');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertNull($invoice->fresh()->mydata_state); // not filed
        // Forensic trail even on a hard transport failure: WHAT WE TRIED TO SEND
        // (the augmented payload) + the error are recorded so it's debuggable.
        $failed = MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_FAILED')->first();
        $this->assertNotNull($failed);
        $this->assertStringContainsString('API_InvoiceDetails', (string) $failed->request);
        $this->assertStringContainsString('Transport error', (string) $failed->response);
    }

    public function test_cancel_transport_failure_records_a_forensic_row_and_keeps_state(): void
    {
        // A 9.3 δελτίο αποστολής is the ONE provider-cancellable type, so it's the
        // only path that reaches the transport — use it to exercise the
        // transport-failure forensic recording.
        $invoice = $this->makeFiledDeliveryNote('400001964594701');

        Http::fake([self::DEMO.'/*' => Http::response('upstream boom', 500)]);

        try {
            app(EInvoiceSubmitterFactory::class)->for($this->tenant->fresh())->cancel($invoice->fresh());
            $this->fail('Expected the cancellation to fail at transport.');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_CANCEL_FAILED')->count());
        $this->assertSame('VALID', $invoice->fresh()->mydata_state); // not flipped
    }

    public function test_production_mode_uses_production_base_and_token(): void
    {
        $prod = Company::create([
            'name' => 'Prod ΑΕ', 'slug' => 'prod-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production', 'afm' => '800561849',
            'einvoice_provider_config' => [
                'base_url' => 'https://live.invosign.test', 'token' => 'LIVE-TOKEN',
                'demo_base_url' => self::DEMO, 'demo_token' => 'DEMO-TOKEN',
            ],
        ]);
        $customer = Customer::create(['company_id' => $prod->id, 'name' => 'Π', 'afm' => '997073525']);
        $type = InvoiceType::create(['company_id' => $prod->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        VatCategory::create(['company_id' => $prod->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $invoice = Invoice::create([
            'company_id' => $prod->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'company_name' => 'Π', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create(['company_id' => $prod->id, 'product_descr' => 'Υ', 'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24]);

        Http::fake(['https://live.invosign.test/*' => Http::response($this->successXml(), 200)]);

        (new InvoSignTransport)->send($invoice->fresh('lines'), '<InvoicesDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"><invoice></invoice></InvoicesDoc>', ProviderCredentials::fromCompany($prod));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'live.invosign.test') && $r['token'] === 'LIVE-TOKEN');
    }

    public function test_cancel_parses_cancellation_mark(): void
    {
        Http::fake([self::DEMO.'/*' => Http::response($this->cancelXml(), 200)]);

        $result = (new InvoSignTransport)->cancel('400001957061986', ProviderCredentials::fromCompany($this->tenant));

        $this->assertTrue($result->success);
        $this->assertSame('400001957363715', $result->cancellationMark);
    }

    public function test_missing_creds_throws(): void
    {
        $bare = Company::create([
            'name' => 'x', 'slug' => 'x-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
        ]);

        $this->expectException(RuntimeException::class);
        (new InvoSignTransport)->cancel('400', ProviderCredentials::fromCompany($bare));
    }

    public function test_end_to_end_factory_submitter_invosign(): void
    {
        Http::fake([self::DEMO.'/*' => Http::response($this->successXml(), 200)]);
        $invoice = $this->makeInvoice();

        // The factory routes gr-provider+invosign+sandbox → GrProviderSubmitter with
        // the real InvoSignTransport.
        $submitter = app(EInvoiceSubmitterFactory::class)->for($this->tenant->fresh());
        $this->assertInstanceOf(GrProviderSubmitter::class, $submitter);

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('400001957061986', $mark->mark);
        $this->assertSame('invosign', $mark->provider_key);
        $this->assertSame('AUTH-XYZ', $mark->authentication_code);
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->count());
        // The stored request is the ACTUAL sent payload (augmented), not the AADE core.
        $this->assertStringContainsString('API_InvoiceDetails', (string) $mark->request);
        $this->assertStringContainsString('statusCode', (string) $mark->response); // what came back
    }

    public function test_non_delivery_note_cancel_is_refused_before_reaching_the_provider(): void
    {
        // A 2.1 invoice cancel is impossible via a provider (CancelDeliveryNote is
        // 9.3-only → InvoSign [283]). The service now PRE-EMPTS that round-trip:
        // it refuses with a credit-note message BEFORE any HTTP, so the provider is
        // never even contacted, no forensic row is written, and state stays VALID.
        $invoice = $this->makeInvoice(); // type 2.1
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400001964594701'])->save();
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001964594701', 'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'invosign',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        Http::fake([self::DEMO.'/*' => Http::response('should not be called', 200)]);

        try {
            app(EInvoiceSubmitterFactory::class)->for($this->tenant->fresh())->cancel($invoice->fresh());
            $this->fail('Expected the cancellation to be refused.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('πιστωτικό', $e->getMessage());
        }

        Http::assertNothingSent(); // the provider was never contacted
        $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)
            ->whereIn('mydata_action', ['PROVIDER_CANCEL', 'PROVIDER_CANCEL_FAILED', 'PROVIDER_CANCEL_REJECTED'])
            ->count());
        $this->assertSame('VALID', $invoice->fresh()->mydata_state); // not flipped
    }

    private function makeInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Υπηρεσία',
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        return $invoice->fresh('lines');
    }

    /**
     * A filed 9.3 δελτίο αποστολής — the one provider-cancellable type. Seeds the
     * PROVIDER_INSERT mark + VALID state directly (cancel() only needs the MARK; a
     * 9.3 payload's delivery fields aren't relevant to the cancel path).
     */
    private function makeFiledDeliveryNote(string $mark): Invoice
    {
        $dnType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'DAP', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'DAP1', 'code' => 1,
            'invoice_type_id' => $dnType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => $mark, 'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'invosign',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => $mark])->save();

        return $invoice->fresh();
    }

    private function successXml(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ResponseDoc><response>'
            .'<index>1</index><invoiceUid>UID1</invoiceUid><invoiceMark>400001957061986</invoiceMark>'
            .'<authenticationCode>AUTH-XYZ</authenticationCode>'
            .'<qrUrl>https://invosign.gr/viewinvoice.php?uid=UID1</qrUrl>'
            .'<statusCode>Success</statusCode><remaining_invoices>2985</remaining_invoices>'
            .'</response></ResponseDoc>';
    }

    private function errorXml(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ResponseDoc><response>'
            .'<index>1</index><statusCode>ValidationError</statusCode><errors><error>'
            .'<message>IssueDate is invalid, it must be equal with current date</message><code>238</code>'
            .'</error></errors></response></ResponseDoc>';
    }

    private function cancelXml(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ResponseDoc><response>'
            .'<cancellationMark>400001957363715</cancellationMark><statusCode>Success</statusCode>'
            .'</response></ResponseDoc>';
    }
}
