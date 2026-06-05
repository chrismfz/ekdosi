<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P6 — the invoice lifecycle is channel-aware: a gr-provider tenant sees a working
 * «Αποστολή στον Πάροχο» action that routes through the factory → GrProviderSubmitter
 * → InvoSign, and a direct myDATA tenant still sees the myDATA submit. The action
 * itself is unchanged (factory-routed); P6 only fixed the visibility gate + labels.
 */
class InvoiceProviderActionTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO = 'https://demo.invosign.test';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
    }

    private function providerTenant(): Company
    {
        return Company::create([
            'name' => 'Provider ΑΕ', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
            'einvoice_provider_config' => ['demo_base_url' => self::DEMO, 'demo_token' => 'DEMO-TOKEN'],
        ]);
    }

    private function draftInvoice(Company $tenant): Invoice
    {
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525']);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY1', 'code' => 1, 'invoice_type_id' => $type->id,
            'customer_id' => $customer->id, 'issued_at' => now(), 'local_status' => 'draft',
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create(['company_id' => $tenant->id, 'product_descr' => 'Υπηρεσία', 'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24]);

        return $invoice->fresh('lines');
    }

    public function test_provider_tenant_sees_and_can_run_the_send_action(): void
    {
        $tenant = $this->providerTenant();
        Filament::setTenant($tenant);
        $invoice = $this->draftInvoice($tenant);

        Http::fake([self::DEMO.'/*' => Http::response(
            '<?xml version="1.0"?><ResponseDoc><response><invoiceMark>400001957061986</invoiceMark>'
            .'<authenticationCode>AUTH-XYZ</authenticationCode><qrUrl>https://invosign.gr/v/x</qrUrl>'
            .'<statusCode>Success</statusCode></response></ResponseDoc>', 200)]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('submit_to_mydata')
            ->callAction('submit_to_mydata')
            ->assertHasNoActionErrors();

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('400001957061986', $fresh->mydata_mark);
        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
    }

    public function test_off_provider_tenant_does_not_see_the_send_action(): void
    {
        $tenant = $this->providerTenant();
        $tenant->update(['einvoice_provider_mode' => 'off']); // staged, not filing
        Filament::setTenant($tenant);
        $invoice = $this->draftInvoice($tenant);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('submit_to_mydata');
    }

    public function test_mydata_tenant_still_sees_the_submit_action(): void
    {
        // Regression guard: the gate change (mydata_mode → submitsElectronically)
        // must NOT hide the submit on a direct-myDATA tenant.
        $tenant = Company::create([
            'name' => 'myDATA ΑΕ', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        Filament::setTenant($tenant);
        $invoice = $this->draftInvoice($tenant);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('submit_to_mydata')
            ->assertActionHidden('preview_provider_payload'); // provider-only
    }

    public function test_provider_tenant_sees_the_payload_preview(): void
    {
        $tenant = $this->providerTenant();
        Filament::setTenant($tenant);
        $invoice = $this->draftInvoice($tenant);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('preview_provider_payload');
    }

    public function test_mark_detail_page_is_accessible_for_a_provider_tenant(): void
    {
        // The MARK→detail link must not 403 for a provider tenant (parity).
        $tenant = $this->providerTenant();
        Filament::setTenant($tenant);

        $this->assertTrue(MyDataMarkDetail::canAccess());
    }
}
