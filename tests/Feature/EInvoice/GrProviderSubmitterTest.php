<?php

namespace Tests\Feature\EInvoice;

use App\Contracts\EInvoiceProviderTransport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\MyDataRejected;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * P2: GrProviderSubmitter files via an injected transport, persists a
 * PROVIDER_INSERT mark (+ provider audit columns) and syncs the invoice mirror —
 * the same source-of-truth shape as the direct myDATA path — plus the §14.4
 * status-check idempotency on an ambiguous send failure. Uses a fake transport
 * (no network, no creds).
 */
class GrProviderSubmitterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Provider tenant', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'fake',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);
    }

    public function test_successful_filing_persists_provider_mark_and_syncs_mirror(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport);

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('400000000000123', $mark->mark);
        $this->assertSame('fake', $mark->provider_key);
        $this->assertSame('AUTHCODE-XYZ', $mark->authentication_code);
        $this->assertSame('https://prov/qr', $mark->invoice_url);

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('active', $fresh->local_status); // draft promoted on filing
        $this->assertSame('400000000000123', $fresh->mydata_mark);
        $this->assertTrue((bool) $fresh->mydata_sent);
    }

    public function test_provider_rejection_throws_and_records_forensic_row_without_filing(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'fail'));

        try {
            $submitter->submit($invoice);
            $this->fail('Expected MyDataRejected.');
        } catch (MyDataRejected $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }

        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_REJECTED')->count());
        $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
        // Mirror untouched — no fake filing.
        $this->assertNull($invoice->fresh()->mydata_state);
    }

    public function test_refuses_to_refile_an_already_valid_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400099999999999'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed at myDATA/');

        (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh());
    }

    public function test_ambiguous_send_failure_adopts_existing_mark_via_status_check(): void
    {
        // §14.4: send() throws (timeout), but status-check finds a MARK → ADOPT it
        // instead of re-filing. The invoice ends VALID with the recovered MARK.
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'throw', status: 'ok'));

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('400000000000123', $mark->mark);
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
    }

    public function test_ambiguous_send_failure_with_nothing_to_adopt_throws(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'throw', status: 'fail'));

        $this->expectException(RuntimeException::class);

        try {
            $submitter->submit($invoice);
        } finally {
            $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
            $this->assertNull($invoice->fresh()->mydata_state);
        }
    }

    public function test_status_check_is_not_consulted_when_send_succeeds(): void
    {
        // N1: a successful send must never call status() — guards against a future
        // refactor that always status-checks. The fake throws on status(); a clean
        // send='ok' must still succeed.
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'ok', status: 'throw'));

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
    }

    public function test_persist_is_idempotent_on_invoice_and_mark(): void
    {
        // N2: if a PROVIDER_INSERT row for (invoice, mark) already exists (e.g. a
        // prior attempt persisted then the response was lost), filing the same MARK
        // again adopts the existing row — no duplicate audit, no second filing.
        $invoice = $this->makeInvoice();
        $existing = MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '400000000000123', // == FakeGrTransport's mark
            'mydata_action' => 'PROVIDER_INSERT',
            'provider_key' => 'fake',
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        $mark = (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice);

        $this->assertSame($existing->id, $mark->id);
        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
    }

    public function test_recovery_does_not_null_out_existing_qr_and_flags_adopted(): void
    {
        // M2: a lighter status response (mark only) must not wipe the QR; the
        // adopted row is flagged delivery_state='ADOPTED'.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['mydata_url' => 'https://existing/qr'])->save();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'throw', status: 'mark-only'));

        $mark = $submitter->submit($invoice);

        $this->assertSame('ADOPTED', $mark->delivery_state);
        $this->assertSame('https://existing/qr', $invoice->fresh()->mydata_url); // not nulled
    }

    public function test_cancel_records_provider_cancel_and_flips_state(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport);
        $submitter->submit($invoice);

        $cancel = $submitter->cancel($invoice->fresh(), 'λάθος ποσό');

        $this->assertSame('PROVIDER_CANCEL', $cancel->mydata_action);
        $this->assertSame('CANCELLED', $invoice->fresh()->mydata_state);
        $this->assertSame('cancelled', $invoice->fresh()->local_status);
    }

    public function test_test_connection_delegates_to_transport_ping(): void
    {
        $this->assertTrue((new GrProviderSubmitter($this->tenant, new FakeGrTransport))->testConnection());
        $this->assertFalse((new GrProviderSubmitter($this->tenant, new FakeGrTransport(ping: false)))->testConnection());
    }

    private function makeInvoice(int $code = 1): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY'.$code,
            'code' => $code,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
        ]);
        $invoice->lines()->create([
            'company_id' => $this->tenant->id,
            'product_descr' => 'Υπηρεσία',
            'qty' => 1,
            'price_per_item' => 100,
            'vat_percent' => 24,
        ]);

        return $invoice->fresh('lines');
    }
}

/** Configurable fake transport — no network. */
class FakeGrTransport implements EInvoiceProviderTransport
{
    public function __construct(
        private readonly string $send = 'ok',
        private readonly string $status = 'ok',
        private readonly bool $ping = true,
    ) {}

    public function key(): string
    {
        return 'fake';
    }

    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return $this->behave($this->send);
    }

    public function sendDelivery(DeliveryNote $note, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return $this->behave($this->send);
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        return ProviderResult::ok(cancellationMark: '400000000000999', raw: '<cancel/>');
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        return $this->behave($this->status);
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        return $this->ping;
    }

    private function behave(string $mode): ProviderResult
    {
        return match ($mode) {
            'throw' => throw new RuntimeException('simulated provider timeout'),
            'fail' => ProviderResult::failed(['[101] simulated validation error'], '<error/>'),
            // A lighter status response — only the MARK came back (no QR/auth).
            'mark-only' => ProviderResult::ok(mark: '400000000000123'),
            default => ProviderResult::ok(
                mark: '400000000000123',
                uid: 'UID-1',
                authenticationCode: 'AUTHCODE-XYZ',
                qrUrl: 'https://prov/qr',
                deliveryState: 'DELIVERED',
                raw: '<response/>',
            ),
        };
    }
}
