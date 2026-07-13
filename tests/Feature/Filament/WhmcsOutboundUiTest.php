<?php

namespace Tests\Feature\Filament;

use App\Filament\PaymentSync\WhmcsOutboundPaymentsTable;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Outbound «Σήμανση Paid στο WHMCS» on ViewInvoice: shown only for an opted-in
 * tenant's settled, WHMCS-linked, not-yet-pushed receivable; the action marks
 * the WHMCS invoice paid via the pusher (fetch/push faked — no HTTP).
 */
class WhmcsOutboundUiTest extends TestCase
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
            'whmcs_push_payments' => true,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δήμος', 'afm' => '090000045']);
        $this->creditTerm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΙΜ', 'code' => 'ΤΙΜ', 'invcount' => 1]);
    }

    private function settledLinkedInvoice(int $whmcsId): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => $this->creditTerm->id,
        ]);
        PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $whmcsId, 'invoice_id' => $invoice->id,
            'payload' => ['status' => 'Unpaid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
        // Real ekdosi payment settles it locally.
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'kind' => 'payment', 'amount' => 124.0, 'pay_date' => '2026-05-25',
        ]);

        return $invoice->fresh();
    }

    private function fakePush(array &$sink): void
    {
        $this->app->instance(WhmcsInvoiceFetcher::class, new class extends WhmcsInvoiceFetcher
        {
            public function __construct() {}

            public function for(Company $tenant): ?callable
            {
                return fn (int $id): array => ['status' => 'Unpaid', 'balance' => 124.0];
            }
        });
        $this->app->instance(WhmcsPaymentPusherFactory::class, new class($sink) extends WhmcsPaymentPusherFactory
        {
            public function __construct(private array &$sink) {}

            public function for(Company $tenant): ?callable
            {
                return function (int $id, float $amount, string $tx): void {
                    $this->sink[] = ['id' => $id, 'amt' => $amount];
                };
            }
        });
    }

    public function test_action_visible_for_a_settled_linked_receivable(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->settledLinkedInvoice(4201);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('mark_paid_at_whmcs');
    }

    public function test_action_hidden_when_tenant_not_opted_in(): void
    {
        $this->tenant->update(['whmcs_push_payments' => false]);
        Filament::setTenant($this->tenant);
        $invoice = $this->settledLinkedInvoice(4202);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('mark_paid_at_whmcs');
    }

    public function test_action_hidden_when_settled_only_by_inbound_sync(): void
    {
        // Anti-echo: an invoice closed ONLY by the inbound sync (whmcs-paid:*)
        // must NOT offer a push back — the button would no-op. Build one settled
        // purely by an inbound-origin payment.
        Filament::setTenant($this->tenant);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => $this->creditTerm->id,
        ]);
        PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 4205, 'invoice_id' => $invoice->id,
            'payload' => ['status' => 'Paid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'kind' => 'payment', 'amount' => 124.0, 'pay_date' => '2026-05-25', 'transaction_id' => 'whmcs-paid:4205',
        ]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getRouteKey()])
            ->assertActionHidden('mark_paid_at_whmcs');
    }

    public function test_action_marks_the_whmcs_invoice_paid(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->settledLinkedInvoice(4203);
        $pushed = [];
        $this->fakePush($pushed);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('mark_paid_at_whmcs')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertCount(1, $pushed);
        $this->assertSame(4203, $pushed[0]['id']);
        $this->assertNotNull($invoice->whmcsPending->fresh()->whmcs_payment_pushed_at);
    }

    public function test_outbound_console_widget_lists_cached_settled_invoices(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->settledLinkedInvoice(4204);
        WhmcsPaymentSyncCache::put($this->tenant, [], [$invoice->id]);

        Livewire::test(WhmcsOutboundPaymentsTable::class)
            ->assertCanSeeTableRecords([$invoice]);
    }
}
