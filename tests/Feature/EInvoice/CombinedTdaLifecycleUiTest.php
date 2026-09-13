<?php

namespace Tests\Feature\EInvoice;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\MyDataSubmitter;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Combined ΤΔΑ — Slice 3d-b: the movement lifecycle wired into the invoice.
 *
 * Covers the two wirings that make a ΤΔΑ's movement lifecycle reachable from the
 * panel: the submitter seeding `delivery_state='registered'` on a tracking-ON
 * filing (so «Έναρξη διακίνησης» surfaces), and ViewInvoice gating the three
 * lifecycle actions on `is_delivery_note && !without_digital_transport_tracking`.
 */
class CombinedTdaLifecycleUiTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'ΤΔΑ 3d-b', 'slug' => 'tda3db-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800561849', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525',
            // Address present so a combined-ΤΔΑ (is_delivery_note) can build the counterpart AADE requires ([204]).
            'address1' => 'Παραλήπτη 5', 'city' => 'Πάτρα', 'postcode' => '26221']);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
    }

    private function tdaType(array $overrides = []): InvoiceType
    {
        return InvoiceType::create(array_merge([
            'company_id' => $this->tenant->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => true, 'show_on_menu' => true,
        ], $overrides));
    }

    /** An unfiled ΤΔΑ ready to submit (buildable movement header). */
    private function tda(array $overrides = []): Invoice
    {
        static $seq = 0;
        $seq++;
        $type = $this->tdaType(['code' => 'ΤΔΑ'.$seq]);

        $invoice = Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΔΑ'.$seq, 'code' => $seq,
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
            'is_delivery_note' => true, 'move_purpose' => 1, 'vehicle_number' => 'ΙΑΒ1234',
            'loading_street' => 'Φόρτωση', 'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοση', 'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ], $overrides));
        $invoice->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Κιβώτια',
            'qty' => 1, 'vat_percent' => 24, 'net_price' => 100, 'gross_price' => 124,
        ]);

        return $invoice->fresh('lines');
    }

    private function successMock(): MockHandler
    {
        $xml = file_get_contents(base_path('tests/Fixtures/firebed/send-invoices-single-response.xml'));

        return new MockHandler([new GuzzleResponse(200, [], $xml)]);
    }

    // ---- submitter seeds the lifecycle state -------------------------------

    public function test_filing_a_tracking_on_tda_seeds_registered_state(): void
    {
        $invoice = $this->tda();

        (new MyDataSubmitter($this->tenant, $this->successMock()))->submit($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNotEmpty($fresh->mydata_url, 'the qrUrl is the lifecycle key');
        $this->assertSame('registered', $fresh->delivery_state, 'a tracking-on ΤΔΑ enters the movement lifecycle');
    }

    public function test_filing_a_tracking_off_tda_leaves_no_lifecycle_state(): void
    {
        $invoice = $this->tda(['without_digital_transport_tracking' => true]);

        (new MyDataSubmitter($this->tenant, $this->successMock()))->submit($invoice);

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        // Tracking OFF → straight to Completed, no lifecycle to drive.
        $this->assertNull($fresh->delivery_state);
    }

    public function test_filing_a_plain_invoice_leaves_delivery_state_null(): void
    {
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'ΤΙΜ',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => false,
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
        ]);
        $invoice->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'X',
            'qty' => 1, 'vat_percent' => 24, 'net_price' => 100, 'gross_price' => 124,
        ]);

        (new MyDataSubmitter($this->tenant, $this->successMock()))->submit($invoice->fresh('lines'));

        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
        $this->assertNull($invoice->fresh()->delivery_state);
    }

    // ---- ViewInvoice gates the movement actions ----------------------------

    private function bootPanel(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($this->tenant);
    }

    /** A filed ΤΔΑ sitting at delivery_state=registered (post-transfer entry point). */
    private function filedTda(array $cache = [], array $overrides = []): Invoice
    {
        $invoice = $this->tda($overrides);
        $invoice->forceFill(array_merge([
            'mydata_sent' => true, 'mydata_state' => 'VALID', 'local_status' => 'active',
            'mydata_mark' => '480301204040191',
            'mydata_url' => 'https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=x',
            'delivery_state' => 'registered',
        ], $cache))->save();

        return $invoice->fresh();
    }

    public function test_view_shows_register_transfer_on_a_registered_tda(): void
    {
        $this->bootPanel();
        $invoice = $this->filedTda();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $this->tenant->slug])
            ->assertActionVisible('register_transfer')
            ->assertActionVisible('refresh_movement_status')
            ->assertActionHidden('confirm_return'); // not in a return-source state yet
    }

    public function test_view_hides_movement_actions_on_a_plain_filed_invoice(): void
    {
        $this->bootPanel();
        // A plain 1.1 filed invoice: flag off → no movement actions at all.
        $invoice = $this->filedTda(['delivery_state' => null], ['is_delivery_note' => false, 'move_purpose' => null]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $this->tenant->slug])
            ->assertActionHidden('register_transfer')
            ->assertActionHidden('refresh_movement_status')
            ->assertActionHidden('confirm_return');
    }

    public function test_view_hides_movement_actions_on_a_tracking_off_tda(): void
    {
        $this->bootPanel();
        // is_delivery_note=true but tracking OFF → no lifecycle → actions hidden.
        $invoice = $this->filedTda(['delivery_state' => null], ['without_digital_transport_tracking' => true]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $this->tenant->slug])
            ->assertActionHidden('register_transfer')
            ->assertActionHidden('refresh_movement_status');
    }

    public function test_view_shows_confirm_return_from_a_failed_state(): void
    {
        $this->bootPanel();
        $invoice = $this->filedTda(['delivery_state' => 'failed']);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $this->tenant->slug])
            ->assertActionVisible('confirm_return')
            ->assertActionHidden('register_transfer'); // not in 'registered'
    }
}
