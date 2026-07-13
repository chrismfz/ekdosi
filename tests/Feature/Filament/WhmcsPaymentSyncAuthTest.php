<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WhmcsPaymentSync;
use App\Filament\PaymentSync\WhmcsOutboundPaymentsTable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Authorization: the money-write actions on the «Συγχρονισμός πληρωμών» page
 * (record inbound payment) and its outbound footer widget (mark WHMCS paid)
 * require Invoice `update` — page access (ViewAny) + opt-in alone must NOT
 * authorize a money-write. Guards the two-reviewer HIGH/MEDIUM finding.
 */
class WhmcsPaymentSyncAuthTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $creditTerm;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        // Everything allowed EXCEPT Invoice `update` — the page/widget still
        // render (ViewAny etc.), but the money actions must be blocked.
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : true);

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

    private function invoice(): Invoice
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
            'payload' => ['status' => 'Unpaid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
    }

    public function test_inbound_record_action_blocked_without_update_permission(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->invoice();   // open → inbound candidate
        $this->filedRow($invoice, 3301);
        WhmcsPaymentSyncCache::put($this->tenant, [$invoice->id]);

        Livewire::test(WhmcsPaymentSync::class)
            ->assertTableActionHidden('record_whmcs_payment', $invoice);
    }

    public function test_outbound_mark_paid_action_blocked_without_update_permission(): void
    {
        Filament::setTenant($this->tenant);
        $invoice = $this->invoice();
        $this->filedRow($invoice, 3302);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'kind' => 'payment', 'amount' => 124.0, 'pay_date' => '2026-05-25',
        ]);
        WhmcsPaymentSyncCache::put($this->tenant, [], [$invoice->id]);

        Livewire::test(WhmcsOutboundPaymentsTable::class)
            ->assertTableActionHidden('mark_paid_at_whmcs', $invoice->fresh());
    }
}
