<?php

namespace Tests\Feature\Filament;

use App\Actions\IssueCreditNote;
use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\RecomputeInvoiceTotals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        // Populate the money caches (gross_total etc.) like a real issued invoice —
        // isFullyCredited() reads gross_total.
        app(RecomputeInvoiceTotals::class)($invoice);

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
            // The local-only «Ακύρωση» is hidden here — on a provider VALID invoice
            // it would desync from AADE; credit note is the only reversal.
            ->assertActionHidden('cancel_local')
            ->assertActionVisible('cancel_via_credit')
            ->assertActionVisible('storno_and_reissue')
            ->assertActionVisible('issue_credit_note')
            // Not yet credited → no «Επανέκδοση».
            ->assertActionHidden('reissue_only');
    }

    public function test_partial_credit_keeps_the_credit_and_cancel_actions(): void
    {
        // A PARTIAL credit (credited < gross) is not a cancellation — the actions
        // must remain so the operator can credit/cancel the rest.
        $tenant = $this->providerTenant();
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        // Credit half of the single line (qty 1 → 0.5).
        app(IssueCreditNote::class)($invoice, $creditType, [
            ['line_id' => $invoice->lines->first()->id, 'qty' => 0.5],
        ]);

        $this->assertFalse($invoice->fresh()->isFullyCredited());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getRouteKey()])
            ->assertActionVisible('cancel_via_credit')
            ->assertActionVisible('issue_credit_note')
            ->assertActionHidden('reissue_only');
    }

    public function test_direct_mydata_fully_credited_also_offers_reissue(): void
    {
        // «Επανέκδοση» is universal: a fully-credited invoice on a direct-myDATA
        // tenant (not a provider) shows it too.
        $tenant = Company::create([
            'name' => 'myDATA ΑΕ', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        app(IssueCreditNote::class)($invoice, $creditType, [
            ['line_id' => $invoice->lines->first()->id, 'qty' => 1],
        ]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getRouteKey()])
            ->assertActionVisible('reissue_only')
            ->assertActionHidden('issue_credit_note')   // nothing left to credit
            ->assertActionHidden('cancel_via_credit');   // provider-only anyway
    }

    public function test_cancel_via_credit_is_info_only_without_a_credit_type(): void
    {
        // No credit type configured → the button still shows (to surface the help),
        // but the modal is info-only: nothing can be issued.
        $tenant = $this->providerTenant();   // deliberately NO creditType()
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('cancel_via_credit');

        $this->assertSame(0, Invoice::where('credited_invoice_id', $invoice->id)->count());
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

        // Bidirectional binding is rendered on BOTH ViewInvoice pages, inside the
        // «Σχετικά παραστατικά» section (assert the section label too, so the code
        // appearing elsewhere can't false-pass).
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee('Σχετικά παραστατικά')
            ->assertSee($credit->invcode);            // original → its credit note
        Livewire::test(ViewInvoice::class, ['record' => $credit->getRouteKey()])
            ->assertSee('Σχετικά παραστατικά')
            ->assertSee($invoice->invcode);           // credit note → the invoice it reverses
    }

    public function test_fully_credited_by_draft_reads_as_reduced_not_cancelled_then_flips_once_filed(): void
    {
        // PROV-019: after a full DRAFT credit the original is fully reduced LOCALLY
        // (reissue offered, further credit/cancel gone) — but it is NOT legally
        // cancelled at AADE yet, so the badge must say «Μειώθηκε με πρόχειρο
        // πιστωτικό», never «Ακυρώθηκε με πιστωτικό». Only once the credit is filed
        // VALID does it read as cancelled.
        $tenant = $this->providerTenant();
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('cancel_via_credit', data: ['credit_type_id' => $creditType->id, 'submit_now' => false]);

        $this->assertTrue($invoice->fresh()->isFullyCredited());
        $this->assertFalse($invoice->fresh()->isLegallyReversed(), 'a draft credit is not a legal reversal');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee('Μειώθηκε με πρόχειρο πιστωτικό')   // honest, un-filed state
            ->assertDontSee('Ακυρώθηκε με πιστωτικό')
            ->assertActionVisible('reissue_only')
            ->assertActionHidden('cancel_via_credit')
            ->assertActionHidden('storno_and_reissue')
            ->assertActionHidden('issue_credit_note');

        // File the credit at AADE → now it IS a legal reversal.
        $credit = Invoice::where('credited_invoice_id', $invoice->id)->firstOrFail();
        $credit->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400000000000002'])->save();

        $this->assertTrue($invoice->fresh()->isLegallyReversed());
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee('Ακυρώθηκε με πιστωτικό');
    }

    public function test_reissue_only_creates_a_fresh_draft_copy(): void
    {
        $tenant = $this->providerTenant();
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('cancel_via_credit', data: ['credit_type_id' => $creditType->id, 'submit_now' => false]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getRouteKey()])
            ->callAction('reissue_only')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        // A fresh draft copy — new id, no credit link, draft, same lines.
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

    public function test_filing_a_replacement_soft_warns_but_is_not_blocked_and_leaves_a_trace(): void
    {
        // PROV-019 soft-warn: the operator chose soft-warn over hard-block, so
        // filing a replacement whose reversed original is still standing at AADE
        // (draft credit) must SUCCEED — not be refused — while leaving a durable
        // trace so «έγινε ενώ το αρχικό στεκόταν» is answerable later.
        $tenant = $this->providerTenant();
        $creditType = $this->creditType($tenant);
        Filament::setTenant($tenant);
        $invoice = $this->validInvoice($tenant, '2.1');

        // Storno & reissue with the credit left as a DRAFT → the original stays
        // VALID at AADE, the reissue is linked back to it.
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('storno_and_reissue', data: ['credit_type_id' => $creditType->id, 'submit_now' => false]);

        $reissue = Invoice::where('reissued_from_invoice_id', $invoice->id)->firstOrFail();
        $this->assertTrue($reissue->replacementReversalPending(), 'original still standing → warn condition holds');

        Http::fake([self::DEMO.'/*' => Http::response(
            '<?xml version="1.0"?><ResponseDoc><response><invoiceMark>400001957062099</invoiceMark>'
            .'<authenticationCode>A</authenticationCode><statusCode>Success</statusCode></response></ResponseDoc>', 200)]);
        Log::spy();

        // Filing the replacement is NOT blocked (soft-warn) — it files at AADE…
        Livewire::test(ViewInvoice::class, ['record' => $reissue->getRouteKey()])
            ->callAction('submit_to_mydata')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertSame('VALID', $reissue->fresh()->mydata_state, 'soft-warn did not block the filing');

        // …and left the durable trace.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'PROV-019'))
            ->once();
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
            // Direct-myDATA keeps the local-only «Ακύρωση» (provider-only gate).
            ->assertActionVisible('cancel_local')
            ->assertActionHidden('cancel_via_credit')
            ->assertActionHidden('storno_and_reissue');
    }
}
