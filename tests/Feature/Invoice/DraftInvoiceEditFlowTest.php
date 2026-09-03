<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The draft edit / issue-date / findability flow.
 *
 * A draft invoice was un-editable through the UI: the EditInvoice page existed
 * and worked, but nothing linked to it (the table had only a ViewAction, the
 * View page no Edit button), so «change the product / price / date» had no entry
 * point. And a provider tenant hit InvoSign 238 on a stale issue date with no
 * one-click fix. And the customer καρτέλα hid drafts entirely, so they could not
 * be found from the customer they belong to.
 */
class DraftInvoiceEditFlowTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $provider = 'gr-mydata', string $mode = 'off'): Company
    {
        return Company::create([
            'name' => 'Tenant',
            'slug' => 'draft-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => $provider,
            'einvoice_provider_mode' => $provider === 'gr-provider' ? $mode : 'off',
            'einvoice_provider_key' => $provider === 'gr-provider' ? 'invosign' : '',
            'mydata_mode' => $provider === 'gr-mydata' ? $mode : 'off',
            'afm' => '800561849',
        ]);
    }

    private int $seq = 2;

    private function draft(Company $tenant, Customer $customer, ?Carbon $issuedAt = null): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $tenant->id, 'code' => 'TIM'],
            ['name' => 'Τιμολόγιο πώλησης', 'invcount' => 1, 'mydata_type' => '2.1'],
        );

        $code = ++$this->seq; // unique per draft within a test (TIM3, TIM4, …)

        return Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TIM'.$code, 'code' => $code,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => $issuedAt ?? now(),
            'local_status' => 'draft',
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
            'gross_total' => 1190.40,
        ]);
    }

    private function customer(Company $tenant): Customer
    {
        return Customer::create(['company_id' => $tenant->id, 'name' => 'Μήχος', 'afm' => '065281387']);
    }

    private function bootPanel(Company $tenant): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);
    }

    // ---- A: the Edit entry point ------------------------------------------

    public function test_view_page_offers_edit_on_a_draft(): void
    {
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertActionVisible('edit_draft');
    }

    public function test_finalize_allocates_the_real_number_for_a_non_transmitting_tenant(): void
    {
        // Gapless-at-send P1: a tenant that does NOT transmit (gr-mydata mode=off →
        // submitsElectronically() = false) has no submission event, so Οριστικοποίηση IS
        // its issuance — it must allocate the real ΑΑ here, not leave it provisional.
        $tenant = $this->tenant(); // gr-mydata, mode=off
        $this->bootPanel($tenant);
        $customer = $this->customer($tenant);
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $tenant->id, 'code' => 'TIM'],
            ['name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '2.1'],
        );
        // A genuine PROVISIONAL draft (no code — the new draft flow).
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'local_status' => 'draft',
        ]);
        $this->assertNull($invoice->code);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->callAction('finalize');

        $fresh = $invoice->fresh();
        $this->assertSame('active', $fresh->local_status);
        $this->assertSame(1, (int) $fresh->code, 'ΑΑ allocated at finalisation for a non-transmitting tenant');
        $this->assertSame('TIM1', $fresh->invcode);
    }

    public function test_view_page_hides_edit_once_filed(): void
    {
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant));
        // Filed at AADE → legally frozen, no edit.
        $invoice->forceFill(['mydata_state' => 'VALID', 'local_status' => 'active', 'mydata_mark' => '400013829677137'])->save();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertActionHidden('edit_draft');
    }

    public function test_table_offers_edit_on_a_draft_only(): void
    {
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $customer = $this->customer($tenant);
        $draft = $this->draft($tenant, $customer);

        $filed = $this->draft($tenant, $customer);
        $filed->forceFill(['mydata_state' => 'VALID', 'local_status' => 'active'])->save();

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('edit', $draft)
            ->assertTableActionHidden('edit', $filed);
    }

    // ---- B: issue date → today (provider only) ----------------------------

    public function test_provider_draft_with_stale_date_offers_set_today(): void
    {
        $tenant = $this->tenant('gr-provider', 'sandbox');
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant), now()->subDays(10));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertActionVisible('set_issue_date_today');
    }

    public function test_set_today_is_hidden_for_direct_mydata(): void
    {
        // Direct myDATA accepts a backdate within AADE's window → the shortcut
        // would be noise; it must not appear.
        $tenant = $this->tenant('gr-mydata', 'sandbox');
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant), now()->subDays(10));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertActionHidden('set_issue_date_today');
    }

    public function test_set_today_is_hidden_when_already_today(): void
    {
        $tenant = $this->tenant('gr-provider', 'sandbox');
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant), now());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertActionHidden('set_issue_date_today');
    }

    public function test_set_today_updates_the_issue_date(): void
    {
        $tenant = $this->tenant('gr-provider', 'sandbox');
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant), now()->subDays(10));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->callAction('set_issue_date_today');

        $this->assertSame(now()->toDateString(), $invoice->fresh()->issued_at->toDateString());
    }

    public function test_set_today_is_hidden_once_filed(): void
    {
        // Never offer a date rewrite on a filed invoice — it is legally frozen.
        // (The action body ALSO re-checks under a row lock, mirroring
        // EditInvoice::beforeSave, for the genuine concurrency window where a
        // filing commits mid-request; that race is not unit-testable in-process,
        // same as the EditInvoice lock — CLAUDE.md notes row-lock tests are
        // MariaDB-only. This asserts the visibility guard, the first line.)
        $tenant = $this->tenant('gr-provider', 'sandbox');
        $this->bootPanel($tenant);
        $invoice = $this->draft($tenant, $this->customer($tenant), now()->subDays(10));
        $invoice->forceFill(['mydata_state' => 'VALID', 'local_status' => 'active'])->save();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertActionHidden('set_issue_date_today');
    }

    // ---- C: drafts findable on the customer καρτέλα -----------------------

    public function test_customer_ledger_lists_the_customers_drafts(): void
    {
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $customer = $this->customer($tenant);
        $draft = $this->draft($tenant, $customer);

        $rows = Livewire::test(CustomerLedger::class, ['record' => $customer->id])
            ->get('draftInvoices');

        $this->assertCount(1, $rows);
        $this->assertSame($draft->id, $rows[0]['id']);
        $this->assertSame('TIM3', $rows[0]['invcode']);
        // A clean draft carries an edit link.
        $this->assertNotNull($rows[0]['edit_url']);
    }

    public function test_customer_ledger_excludes_a_filed_draft_status_row(): void
    {
        // A row that is draft-status but carries a MARK is a filed AADE document,
        // not a draft — «Πρόχειρα» must not present it as one. The section lists
        // exactly what the edit surfaces accept (mydata_state === null), so this
        // row is excluded entirely rather than shown with a dead edit link.
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $customer = $this->customer($tenant);

        $draft = $this->draft($tenant, $customer);
        $draft->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400013829677137'])->save();

        $rows = Livewire::test(CustomerLedger::class, ['record' => $customer->id])
            ->get('draftInvoices');

        $this->assertCount(0, $rows);
    }

    public function test_customer_ledger_drafts_exclude_issued_and_cancelled(): void
    {
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $customer = $this->customer($tenant);

        $issued = $this->draft($tenant, $customer);
        $issued->forceFill(['local_status' => 'active', 'mydata_state' => 'VALID'])->save();

        $cancelled = $this->draft($tenant, $customer);
        $cancelled->forceFill(['local_status' => 'cancelled'])->save();

        $rows = Livewire::test(CustomerLedger::class, ['record' => $customer->id])
            ->get('draftInvoices');

        $this->assertCount(0, $rows);
    }

    public function test_customer_ledger_drafts_exclude_legacy_imported(): void
    {
        // onlyUnissuedDrafts keeps NEW-app drafts only: a legacy-imported row with
        // a stuck draft status is historical, not a live pre-invoice.
        $tenant = $this->tenant();
        $this->bootPanel($tenant);
        $customer = $this->customer($tenant);

        $legacy = $this->draft($tenant, $customer);
        $legacy->forceFill(['legacy_id' => 42])->save();

        $rows = Livewire::test(CustomerLedger::class, ['record' => $customer->id])
            ->get('draftInvoices');

        $this->assertCount(0, $rows);
    }
}
