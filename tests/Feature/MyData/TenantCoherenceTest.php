<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Services\Delivery\DeliveryNoteSubmitter;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Services\MyDataSubmitter;
use App\Support\Tenancy\CompanyContext;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * MYD-022 — filing services must prove the document and the issuing tenant agree.
 *
 * The services receive a `Company $tenant` INDEPENDENTLY of the document: issuer
 * ΑΦΜ, branch, myDATA credentials and provider contract come from the tenant, while
 * counterpart, type, lines and payment method come from the document. Nothing
 * checked that the two agree, so a programming error or a crafted service/API/CLI
 * call could transmit tenant B's commercial data under tenant A's ΑΦΜ — a false
 * filing AND a cross-tenant confidentiality incident, both irreversible once AADE
 * has issued a MARK.
 *
 * The panel's tenant scoping is NOT the boundary: `CompanyScope` is a documented
 * no-op outside a request (CLI, queue, webhooks), which is exactly where automation
 * runs. These tests therefore call the services directly, the way automation does.
 *
 * Every test asserts three things, because "it threw" is not the requirement:
 * it threw, NO audit row was written, and the outbound queue was NOT touched.
 * The MockHandler count is the real proof that nothing reached the wire.
 */
class TenantCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $issuer;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issuer = $this->company('issuer');
        $this->other = $this->company('other');
    }

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => 'Εταιρεία '.$slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => $slug === 'issuer' ? '800561849' : '801280908',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
            'tax_office' => 'ΚΕΦΟΔΕ', 'address' => 'ΑΔΡΙΑΝΟΥ 16', 'city' => 'ΑΘΗΝΑ', 'postcode' => '14121',
        ]);
    }

    /** A complete, filable invoice owned entirely by $owner. */
    private function invoiceFor(Company $owner, string $invcode = 'ΤΠΥ1', int $code = 1): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $owner->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $customer = Customer::create([
            'company_id' => $owner->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '997073525',
        ]);
        VatCategory::create([
            'company_id' => $owner->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);

        $invoice = Invoice::create([
            'company_id' => $owner->id, 'invcode' => $invcode, 'code' => $code,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'local_status' => 'active',
            'country' => 'GR', 'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
        ]);
        InvoiceLine::create([
            'company_id' => $owner->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
            'net_price' => 100, 'gross_price' => 124, 'product_descr' => 'Υπηρεσία',
        ]);

        return $invoice->fresh('lines');
    }

    private function deliveryNoteFor(Company $owner): DeliveryNote
    {
        $type = InvoiceType::create([
            'company_id' => $owner->id, 'code' => 'ΔΑ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $customer = Customer::create([
            'company_id' => $owner->id, 'name' => 'Παραλήπτης ΑΕ', 'afm' => '123456789',
        ]);

        $note = DeliveryNote::create([
            'company_id' => $owner->id, 'delivery_type_id' => $type->id,
            'customer_id' => $customer->id, 'invcode' => 'ΔΑ1', 'code' => 1,
            'issued_at' => now(), 'mydata_type' => '9.3', 'move_purpose' => 1,
            'local_status' => 'active', 'dispatch_at' => now()->addHour(),
            'vehicle_number' => 'ΙΑΒ1234', 'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789', 'recipient_country' => 'GR',
            'loading_street' => 'Φόρτωσης', 'loading_number' => '10',
            'loading_postcode' => '11111', 'loading_city' => 'Αθήνα', 'start_shipping_branch' => 0,
            'delivery_street' => 'Παράδοσης', 'delivery_number' => '20',
            'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη', 'complete_shipping_branch' => 0,
        ]);
        $note->lines()->create([
            'company_id' => $owner->id, 'product_descr' => 'Server', 'qty' => 2,
        ]);

        return $note->fresh('lines');
    }

    /**
     * The shared assertion: it threw for a tenant reason, nothing was audited, and
     * the outbound queue was never touched.
     */
    private function assertRefusedBeforeAnything(callable $call, MockHandler $mock): void
    {
        $queued = $mock->count();

        try {
            $call();
            $this->fail('Expected a tenant-coherence refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tenant mismatch', $e->getMessage());
        }

        $this->assertSame(0, MyDataMark::count(), 'no audit row may be written');
        $this->assertSame(0, DeliveryMark::count(), 'no audit row may be written');
        $this->assertSame($queued, $mock->count(), 'nothing may reach the wire');
    }

    // ───────────────────────── direct myDATA (invoice) ─────────────────────

    public function test_direct_submit_refuses_another_tenants_invoice(): void
    {
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->other);

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->submit($invoice),
            $mock,
        );
    }

    public function test_direct_cancel_refuses_another_tenants_invoice(): void
    {
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->other);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400001965177931'])->save();

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->cancel($invoice, 'λάθος'),
            $mock,
        );
    }

    public function test_dry_run_preview_refuses_another_tenants_invoice(): void
    {
        // previewXml builds the real payload AND writes a DRY_RUN audit row, so a
        // mismatch would leave a stored document asserting the wrong issuer.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->other);

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->previewXml($invoice),
            $mock,
        );
    }

    // ───────────────────────────── provider path ──────────────────────────

    public function test_provider_submit_refuses_another_tenants_invoice(): void
    {
        // The sharpest case: InvoSignDocument reads its issuer fields from
        // $invoice->company while the credentials come from $this->tenant, so a
        // mismatched call yields ONE payload asserting TWO different issuers.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->other);

        $this->assertRefusedBeforeAnything(
            fn () => (new GrProviderSubmitter($this->issuer, new NullProviderTransport))->submit($invoice),
            $mock,
        );
    }

    public function test_provider_cancel_refuses_another_tenants_invoice(): void
    {
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->other);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400001965177931'])->save();

        $this->assertRefusedBeforeAnything(
            fn () => (new GrProviderSubmitter($this->issuer, new NullProviderTransport))->cancel($invoice, 'λάθος'),
            $mock,
        );
    }

    // ─────────────────────────── delivery notes ───────────────────────────

    public function test_delivery_submit_refuses_another_tenants_note(): void
    {
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $note = $this->deliveryNoteFor($this->other);

        $this->assertRefusedBeforeAnything(
            fn () => (new DeliveryNoteSubmitter($this->issuer, $mock))->submit($note),
            $mock,
        );
    }

    public function test_delivery_preview_refuses_another_tenants_note(): void
    {
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $note = $this->deliveryNoteFor($this->other);

        $this->assertRefusedBeforeAnything(
            fn () => (new DeliveryNoteSubmitter($this->issuer, $mock))->previewXml($note),
            $mock,
        );
    }

    public function test_every_delivery_lifecycle_event_refuses_another_tenants_note(): void
    {
        // Register / confirm / refresh / cancel are all filed under the tenant's
        // ΑΦΜ and credentials just like the issue itself.
        $note = $this->deliveryNoteFor($this->other);
        $note->forceFill([
            'mydata_state' => 'VALID', 'mydata_mark' => '400001965177931',
            'delivery_state' => 'registered',
        ])->save();

        foreach ([
            'registerTransfer' => fn (DeliveryLifecycleService $s) => $s->registerTransfer($note),
            'confirmDelivery' => fn (DeliveryLifecycleService $s) => $s->confirmDelivery($note),
            'refreshStatus' => fn (DeliveryLifecycleService $s) => $s->refreshStatus($note),
            'cancel' => fn (DeliveryLifecycleService $s) => $s->cancel($note, 'λάθος'),
        ] as $name => $call) {
            $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
            $service = new DeliveryLifecycleService($this->issuer, $mock);

            $this->assertRefusedBeforeAnything(fn () => $call($service), $mock);
        }
    }

    // ──────────────────── the relations, not just the document ────────────

    public function test_a_relation_belonging_to_another_tenant_is_refused(): void
    {
        // The document itself is ours, but a relation whose values reach the
        // payload is not. This is the realistic shape of the bug: a mis-set
        // customer_id or invoice_type_id, not a wholesale wrong invoice.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        $foreignCustomer = Customer::create([
            'company_id' => $this->other->id, 'name' => 'Ξένος', 'afm' => '123456789',
        ]);
        $invoice->forceFill(['customer_id' => $foreignCustomer->id])->save();

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines')),
            $mock,
        );
    }

    public function test_a_foreign_invoice_line_is_refused(): void
    {
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        InvoiceLine::create([
            'company_id' => $this->other->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24,
            'net_price' => 50, 'gross_price' => 62, 'product_descr' => 'Ξένη γραμμή',
        ]);

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines')),
            $mock,
        );
    }

    public function test_a_foreign_relation_is_caught_inside_the_panel_too(): void
    {
        // The relation half of this guard was decorative in the panel. CompanyScope
        // filters a lazy load by the AMBIENT tenant, so a cross-tenant customer_id
        // resolved to NULL — and a null relation is legitimately allowed (an invoice
        // may simply have no payment method). So it fired from CLI and queue but
        // stayed silent exactly where an operator sits: the opposite of the
        // context-independence this class promises. Reading past the scope makes the
        // foreign row visible so it can be refused.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        $foreignCustomer = Customer::create([
            'company_id' => $this->other->id, 'name' => 'Ξένος', 'afm' => '123456789',
        ]);
        $invoice->forceFill(['customer_id' => $foreignCustomer->id])->save();

        // Ambient tenant set, exactly as Filament sets it on TenantSet.
        app(CompanyContext::class)->actAs($this->issuer, function () use ($invoice, $mock): void {
            $this->assertRefusedBeforeAnything(
                fn () => (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines')),
                $mock,
            );
        });
    }

    public function test_a_missing_relation_is_not_a_tenant_failure(): void
    {
        // The other direction: reading past the scope must not turn "no payment
        // method" into a refusal. A broken FK is likewise not a tenant leak — the
        // payload builders report that far better than a coherence error would.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<nonsense/>')]);
        $invoice = $this->invoiceFor($this->issuer);
        $invoice->forceFill(['payment_method_id' => null])->save();

        try {
            (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines'));
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('Tenant mismatch', $e->getMessage());
        }

        $this->assertSame(0, $mock->count(), 'a document with no payment method must still reach the wire');
    }

    /** A product owned by $owner, its category owned by $categoryOwner (default: the same). */
    private function productFor(Company $owner, ?Company $categoryOwner = null): Product
    {
        $categoryOwner ??= $owner;

        $category = ProductCategory::create([
            'company_id' => $categoryOwner->id, 'description_short' => 'Κατηγορία',
        ]);
        $vat = VatCategory::where('company_id', $owner->id)->first()
            ?? VatCategory::create([
                'company_id' => $owner->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
            ]);

        return Product::create([
            'company_id' => $owner->id, 'description_short' => 'Είδος',
            'product_category_id' => $category->id, 'vat_category_id' => $vat->id,
        ]);
    }

    public function test_a_line_product_from_another_tenant_is_refused(): void
    {
        // AadeInvoiceDocument resolves the per-line E3 income classification through
        // lines.product.productCategory, so a foreign product files another tenant's
        // classification under this tenant's ΑΦΜ.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        $invoice->lines()->update(['product_id' => $this->productFor($this->other)->id]);

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines')),
            $mock,
        );
    }

    public function test_a_product_category_from_another_tenant_is_refused(): void
    {
        // One level deeper: our own product, but pointed at a foreign category —
        // which is the half that actually drives the classification override.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<ok/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        $product = $this->productFor($this->issuer, categoryOwner: $this->other);
        $invoice->lines()->update(['product_id' => $product->id]);

        $this->assertRefusedBeforeAnything(
            fn () => (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines')),
            $mock,
        );
    }

    public function test_our_own_product_and_category_are_not_refused(): void
    {
        // The other direction, and the reason the check is one query per level
        // rather than a lazy load per line.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<nonsense/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        $invoice->lines()->update(['product_id' => $this->productFor($this->issuer)->id]);

        try {
            (new MyDataSubmitter($this->issuer, $mock))->submit($invoice->fresh('lines'));
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('Tenant mismatch', $e->getMessage());
        }

        $this->assertSame(0, $mock->count());
    }

    // ───────────────────────── the other direction ────────────────────────

    public function test_a_coherent_invoice_is_not_refused(): void
    {
        // The guard must not strand legitimate filings — the failure mode that
        // matters as much as the leak it prevents. Reaching the AADE call at all
        // proves the guard let it through; the mock's response is deliberately
        // unusable, so we only assert it did NOT fail for a tenant reason.
        $mock = new MockHandler([new GuzzleResponse(200, [], '<nonsense/>')]);
        $invoice = $this->invoiceFor($this->issuer);

        try {
            (new MyDataSubmitter($this->issuer, $mock))->submit($invoice);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('Tenant mismatch', $e->getMessage());
        }

        $this->assertSame(0, $mock->count(), 'a coherent invoice must reach the wire');
    }

    public function test_an_int_vs_string_company_id_still_matches(): void
    {
        // A company_id read back through a query builder can be a string on some
        // drivers. '3' and 3 are the same tenant; comparing loosely would be wrong
        // in the other direction (null == 0), so the check casts to int.
        $invoice = $this->invoiceFor($this->issuer);
        $invoice->setAttribute('company_id', (string) $this->issuer->id);

        $mock = new MockHandler([new GuzzleResponse(200, [], '<nonsense/>')]);

        try {
            (new MyDataSubmitter($this->issuer, $mock))->submit($invoice);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('Tenant mismatch', $e->getMessage());
        }

        $this->assertSame(0, $mock->count());
    }
}
