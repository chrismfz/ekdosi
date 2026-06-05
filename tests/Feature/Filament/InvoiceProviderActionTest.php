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
            ->assertHasNoActionErrors()
            // The success path ends in a redirect — it only runs if the success
            // notification didn't throw (guards the «Undefined variable» class of
            // bug, where filing succeeded but the closure referenced a page-scope
            // var it couldn't see, so the catch reported a false "failed").
            ->assertRedirect();

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

    /** A VALID (filed) invoice of the given myDATA type, on the given tenant. */
    private function validInvoice(Company $tenant, string $mydataType = '2.1'): Invoice
    {
        $invoice = $this->draftInvoice($tenant);
        // Original is #1; advance the counter so a reissue allocates #2 (not a
        // collision with the manually-created TPY1).
        $invoice->invoiceType->update(['mydata_type' => $mydataType, 'invcount' => 2]);
        // mydata_state/mydata_mark are guarded (written via forceFill by the
        // submitter), so a plain update() would silently drop them.
        $invoice->forceFill([
            'local_status' => 'active', 'mydata_state' => 'VALID', 'mydata_mark' => '400000000000001',
        ])->save();

        return $invoice->fresh('lines');
    }

    private function creditType(Company $tenant): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'PT', 'name' => 'Πιστωτικό',
            'invcount' => 1, 'is_credit' => true, 'mydata_type' => '5.1',
        ]);
    }

    public function test_provider_marked_invoice_hides_cancel_and_offers_credit_path(): void
    {
        // The locked ΥΠΑΗΕΣ rule: a MARKed 2.1 can't be cancelled via the
        // provider — the cancel button is gone, the explainer + storno take over.
        $tenant = $this->providerTenant();
        $this->creditType($tenant);                 // makes storno visible
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('cancel_at_mydata')
            ->assertActionVisible('cancel_via_credit')
            ->assertActionVisible('storno_and_reissue')
            ->assertActionVisible('issue_credit_note');
    }

    public function test_cancel_via_credit_issues_a_full_credit_bound_to_the_original(): void
    {
        // The popup-turned-action: one click issues a FULL credit note that
        // reverses the original and binds the two via credited_invoice_id.
        $tenant = $this->providerTenant();
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('cancel_via_credit', data: ['credit_type_id' => $creditType->id, 'submit_now' => false])
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $credit = Invoice::where('credited_invoice_id', $invoice->id)->first();
        $this->assertNotNull($credit);
        $this->assertSame('draft', $credit->local_status);
        // Full reversal: the original's credited_total equals the credit's gross.
        $this->assertGreaterThan(0, (float) $credit->gross_total);
        $this->assertEqualsWithDelta((float) $credit->gross_total, (float) $invoice->fresh()->credited_total, 0.01);

        // Bidirectional binding is rendered on BOTH ViewInvoice pages.
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee($credit->invcode);            // original → its credit note
        Livewire::test(ViewInvoice::class, ['record' => $credit->getRouteKey()])
            ->assertSee($invoice->invcode);           // credit note → the invoice it reverses
    }

    public function test_provider_delivery_note_keeps_the_real_cancel(): void
    {
        // 9.3 δελτίο αποστολής IS cancellable via the provider — the real
        // cancel stays, the explainer/storno do NOT show.
        $tenant = $this->providerTenant();
        $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '9.3');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('cancel_at_mydata')
            ->assertActionHidden('cancel_via_credit')
            ->assertActionHidden('storno_and_reissue');
    }

    public function test_storno_and_reissue_creates_credit_plus_draft_and_redirects_to_edit(): void
    {
        $tenant = $this->providerTenant();
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        // Default path: submit_now OFF → no provider HTTP call (no Http::fake needed).
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('storno_and_reissue', data: ['credit_type_id' => $creditType->id, 'submit_now' => false])
            ->assertHasNoActionErrors()
            ->assertRedirect();

        // Full credit note against the original (positive lines, draft).
        $credit = Invoice::where('credited_invoice_id', $invoice->id)->first();
        $this->assertNotNull($credit);
        $this->assertSame('draft', $credit->local_status);

        // A fresh draft copy of the original — new id, no MARK, not a credit.
        $reissue = Invoice::where('company_id', $tenant->id)
            ->whereNull('credited_invoice_id')
            ->where('id', '!=', $invoice->id)
            ->where('local_status', 'draft')
            ->first();
        $this->assertNotNull($reissue);
        $this->assertNull($reissue->mydata_state);
        $this->assertSame($invoice->invoice_type_id, $reissue->invoice_type_id);
        $this->assertCount(1, $reissue->lines);
    }

    public function test_mydata_tenant_keeps_the_real_cancel_on_a_marked_invoice(): void
    {
        // Regression: the 9.3 gate is PROVIDER-only. A direct-myDATA tenant
        // (AADE CancelInvoice cancels a 2.1) must still see the real cancel and
        // NOT the provider explainer/storno.
        $tenant = Company::create([
            'name' => 'myDATA ΑΕ', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('cancel_at_mydata')
            ->assertActionHidden('cancel_via_credit')
            ->assertActionHidden('storno_and_reissue');
    }
}
