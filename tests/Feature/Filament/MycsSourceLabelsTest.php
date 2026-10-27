<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Support\Billing\SourceName;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A row or invoice from MyCS never reads «WHMCS» to the operator (live test of 2026-10-27): the create-draft preview,
 * the inbox actions and the invoice's payment buttons name the source it came from. And recording a payment on an
 * invoice shows it paid at once (the page held the balance it had read).
 */
class MycsSourceLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x')]));
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'M', 'slug' => 'm-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mycs_enabled' => true, 'mycs_url' => 'https://my.example.test', 'mycs_secret' => str_repeat('s', 40),
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 15, 'mydata_payment_type' => 5]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $this->credit->id]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΑΚΜΕ', 'afm' => '111111111']);
        Filament::setTenant($this->tenant);
    }

    /** @param  array<string, mixed>  $extra */
    private function row(bool $mycs, array $extra = []): PendingWhmcsInvoice
    {
        $id = random_int(1000, 99999);

        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $id,
            'payload' => $extra + [
                'invoiceid' => $id, 'status' => 'Unpaid', 'total' => '24.80', 'subtotal' => '20.00', 'tax' => '4.80', 'taxrate' => '24.00',
                'items' => ['item' => [['id' => 1, 'description' => 'Hosting', 'amount' => '20.00', 'taxed' => '1']]],
            ] + ($mycs ? ['mycs' => ['requested_at' => '2026-10-27T13:55:05Z']] : []),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_the_source_name_has_the_greek_articles(): void
    {
        $mycs = SourceName::for(true);
        $whmcs = SourceName::for(false);

        $this->assertSame(['MyCS', 'τη MyCS', 'Η MyCS', 'της MyCS', 'στη MyCS', 'MyCS #7'],
            [$mycs->label(), $mycs->the(), $mycs->subject(), $mycs->of(), $mycs->in(), $mycs->ref(7)]);
        $this->assertSame(['WHMCS', 'το WHMCS', 'Το WHMCS', 'του WHMCS', 'στο WHMCS', 'WHMCS #7'],
            [$whmcs->label(), $whmcs->the(), $whmcs->subject(), $whmcs->of(), $whmcs->in(), $whmcs->ref(7)]);
    }

    public function test_the_preview_of_a_mycs_row_names_mycs(): void
    {
        $render = fn (PendingWhmcsInvoice $row): string => view('filament.whmcs-inbox.preview-partial', ['preview' => app(WhmcsInvoiceFiler::class)
            ->preview($this->tenant, $row, $this->customer, $this->type)])->render();

        $mycs = $render($this->row(true));
        $this->assertStringContainsString('Από MyCS παραστατικό', $mycs);
        $this->assertStringContainsString('αξία MyCS', $mycs);
        $this->assertStringNotContainsString('WHMCS', $mycs);

        $this->assertStringContainsString('Από WHMCS παραστατικό', $render($this->row(false)), 'a WHMCS row as it was');
    }

    public function test_the_payment_check_on_a_mycs_invoice_names_mycs(): void
    {
        $invoice = $this->openInvoice();
        $row = $this->row(true);
        $row->update(['invoice_id' => $invoice->id, 'status' => PendingWhmcsInvoice::STATUS_FILED]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('check_whmcs_paid')
            ->assertActionHasLabel('check_whmcs_paid', 'Έχει πληρωθεί στη MyCS;');
    }

    public function test_the_payment_check_on_a_whmcs_invoice_still_names_whmcs(): void
    {
        $this->tenant->update(['whmcs_api_url' => 'https://whmcs.test/includes/api.php', 'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret']);
        $invoice = $this->openInvoice();
        $row = $this->row(false);
        $row->update(['invoice_id' => $invoice->id, 'status' => PendingWhmcsInvoice::STATUS_FILED]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHasLabel('check_whmcs_paid', 'Έχει πληρωθεί στο WHMCS;');
    }

    public function test_recording_a_payment_shows_the_invoice_paid_at_once(): void
    {
        $invoice = $this->openInvoice();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('record_payment', data: ['amount' => '124.00', 'pay_date' => '2026-10-27', 'payment_method_id' => $this->credit->id])
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertSame(0.0, round($invoice->fresh()->balanceData()->balance, 2));
    }

    private function openInvoice(): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-10-27 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => $this->credit->id,
        ]);
    }
}
