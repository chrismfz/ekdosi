<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WhmcsPaymentSync;
use App\Filament\Widgets\WhmcsPaymentSyncStats;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The central «Συγχρονισμός πληρωμών» page + dashboard tile: reads the cached
 * inbound worklist, lists it, and closes each receivable with a one-click
 * «Καταγραφή πληρωμής» (fetcher faked — no HTTP).
 */
class WhmcsPaymentSyncPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $creditTerm;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]));

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

    private function openInvoice(): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => $this->creditTerm->id,
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

    public function test_page_lists_the_cached_inbound_worklist(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 9001);
        WhmcsPaymentSyncCache::put($this->tenant, [$invoice->id]);

        Livewire::test(WhmcsPaymentSync::class)
            ->assertCanSeeTableRecords([$invoice]);
    }

    public function test_record_action_closes_the_receivable_and_drops_the_row(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 9002);
        WhmcsPaymentSyncCache::put($this->tenant, [$invoice->id]);
        $this->fakeFetch(['status' => 'Paid', 'datepaid' => '2026-05-22']);

        Livewire::test(WhmcsPaymentSync::class)
            ->callTableAction('record_whmcs_payment', $invoice)
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, Payment::where('transaction_id', 'whmcs-paid:9002')->count());
        // Removed from the cached worklist immediately.
        $this->assertSame([], WhmcsPaymentSyncCache::inboundIds($this->tenant->fresh()));
    }

    public function test_refresh_action_recomputes_the_worklist(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 9003);
        $this->fakeFetch(['status' => 'Paid']);   // WHMCS reports it paid

        // Cache starts empty; «Ανανέωση τώρα» detects and fills it.
        $this->assertSame([], WhmcsPaymentSyncCache::inboundIds($this->tenant));

        Livewire::test(WhmcsPaymentSync::class)
            ->callAction('refresh')
            ->assertHasNoActionErrors();

        $this->assertSame([$invoice->id], WhmcsPaymentSyncCache::inboundIds($this->tenant->fresh()));
    }

    public function test_dashboard_tile_visible_only_when_there_is_work(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->openInvoice();

        // Empty cache → tile hidden.
        $this->assertFalse(WhmcsPaymentSyncStats::canView());

        WhmcsPaymentSyncCache::put($this->tenant, [$invoice->id]);
        $this->assertTrue(WhmcsPaymentSyncStats::canView());
    }

    public function test_page_access_gated_to_whmcs_tenants(): void
    {
        Filament::setTenant($this->tenant);
        $this->assertTrue(WhmcsPaymentSync::canAccess());

        $plain = Company::create([
            'name' => 'No WHMCS', 'slug' => 'nw-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($plain);
        $this->assertFalse(WhmcsPaymentSync::canAccess());
    }
}
