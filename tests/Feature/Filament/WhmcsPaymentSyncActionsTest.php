<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two on-demand triggers for the inbound WHMCS payment sync:
 *  - inbox header action «Συγχρονισμός πληρωμών τώρα» (bulk, mirrors the cron),
 *  - per-invoice action «Έχει πληρωθεί στο WHMCS;» (single open receivable).
 * Both resolve the tenant's fetcher, run WhmcsPaymentSyncer, and surface the
 * result. The fetcher is faked so no HTTP goes out; the safety logic itself is
 * covered in WhmcsPaymentSyncerTest. Both are money-writes, so both are gated
 * (Update:PendingWhmcsInvoice / Invoice update) — the denial tests guard that.
 */
class WhmcsPaymentSyncActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $creditTerm;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));

        $this->tenant = Company::create([
            'name' => 'PS', 'slug' => 'ps-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.test/includes/api.php',
            'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δήμος', 'afm' => '090000045']);
        $this->creditTerm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΙΜ', 'code' => 'ΤΙΜ', 'invcount' => 1]);
    }

    /** Grant every ability (the common case — a fully-permitted operator). */
    private function allowAll(): void
    {
        Gate::before(fn () => true);
    }

    /**
     * Grant page access (ViewAny etc.) but DENY the given ability — so a denial
     * test can render the page yet find the money-write action hidden.
     */
    private function allowAllExcept(string $deniedAbility): void
    {
        Gate::before(fn ($user, string $ability) => $ability === $deniedAbility ? false : true);
    }

    /** Bind a fetcher that always returns the given WHMCS payload (no HTTP). */
    private function fakeFetch(array $payload): void
    {
        $this->app->instance(WhmcsInvoiceFetcher::class, new class($payload) extends WhmcsInvoiceFetcher
        {
            public function __construct(private array $payload) {}

            public function for(Company $tenant): ?callable
            {
                return fn (int $id): array => $this->payload;
            }
        });
    }

    private function openInvoice(?PaymentMethod $pm = null): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => ($pm ?? $this->creditTerm)->id,
        ]);
    }

    private function filedRow(Invoice $invoice, int $whmcsId): void
    {
        PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $whmcsId, 'invoice_id' => $invoice->id,
            'payload' => ['status' => 'Paid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
    }

    // --- (α) inbox header action -------------------------------------------------

    public function test_inbox_header_action_visible_for_a_whmcs_tenant(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);

        Livewire::test(ListWhmcsInbox::class)
            ->assertActionVisible('sync_whmcs_payments');
    }

    public function test_inbox_header_action_hidden_for_a_non_whmcs_tenant(): void
    {
        $this->allowAll();
        $plain = Company::create([
            'name' => 'No WHMCS', 'slug' => 'nw-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($plain);

        Livewire::test(ListWhmcsInbox::class)
            ->assertActionHidden('sync_whmcs_payments');
    }

    public function test_inbox_header_action_hidden_without_update_permission(): void
    {
        // Money-write gate: a user with inbox read access but NOT
        // Update:PendingWhmcsInvoice must not reach the tenant-wide sync.
        $this->allowAllExcept('Update:PendingWhmcsInvoice');
        Filament::setTenant($this->tenant);

        Livewire::test(ListWhmcsInbox::class)
            ->assertActionHidden('sync_whmcs_payments');
    }

    public function test_inbox_header_action_records_paid_open_invoices(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 7001);
        $this->fakeFetch(['status' => 'Paid', 'datepaid' => '2026-05-22']);

        Livewire::test(ListWhmcsInbox::class)
            ->callAction('sync_whmcs_payments')
            ->assertHasNoActionErrors();

        $this->assertSame(1, Payment::where('transaction_id', 'whmcs-paid:7001')->count());
    }

    // --- (β) per-invoice action --------------------------------------------------

    public function test_per_invoice_action_visible_on_an_open_linked_receivable(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 7002);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('check_whmcs_paid');
    }

    public function test_per_invoice_action_hidden_without_a_whmcs_link(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();   // no filed pending row

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('check_whmcs_paid');
    }

    public function test_per_invoice_action_hidden_on_a_cash_term_invoice(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $invoice = $this->openInvoice($cash);
        $this->filedRow($invoice, 7003);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('check_whmcs_paid');
    }

    public function test_per_invoice_action_hidden_without_update_permission(): void
    {
        // Same money-write gate on the single-invoice path: view access but no
        // Invoice `update` → the «Έχει πληρωθεί;» action is hidden.
        $this->allowAllExcept('update');
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 7006);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('check_whmcs_paid');
    }

    public function test_per_invoice_action_records_the_payment_when_paid(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 7004);
        $this->fakeFetch(['status' => 'Paid', 'datepaid' => '2026-05-22']);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('check_whmcs_paid')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $payment = Payment::where('transaction_id', 'whmcs-paid:7004')->first();
        $this->assertNotNull($payment);
        $this->assertEqualsWithDelta(124.0, (float) $payment->amount, 0.001);
    }

    public function test_per_invoice_action_records_nothing_when_still_unpaid(): void
    {
        $this->allowAll();
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 7005);
        $this->fakeFetch(['status' => 'Unpaid']);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('check_whmcs_paid')
            ->assertHasNoActionErrors();

        $this->assertSame(0, Payment::where('company_id', $this->tenant->id)->count());
    }
}
