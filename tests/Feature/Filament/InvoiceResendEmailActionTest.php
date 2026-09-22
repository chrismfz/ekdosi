<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Jobs\SendInvoiceEmail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Αποστολή PDF στον πελάτη» header action carries an OPTIONAL recipient
 * field: left blank it sends to the invoice's customer (the normal path); typed
 * in, it sends a targeted copy to that address (π.χ. στον λογιστή) with no
 * customer CC. These drive the ViewInvoice action end-to-end and assert the
 * dispatched SendInvoiceEmail's toOverride — the one thing that decides who gets
 * the mail. (The job's own override behaviour — CC skipped, BCC kept, recipient
 * logged — is covered by SendInvoiceEmailTest.)
 */
class InvoiceResendEmailActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Gate::before(fn () => true);

        $this->tenant = Company::create([
            'name' => 'Acme', 'slug' => 'resend-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function issuedInvoice(?string $customerEmail = 'c@example.com'): Invoice
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'email' => $customerEmail]);

        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $customer->id,
            'local_status' => 'active', 'issued_at' => now(),
            'company_name' => 'C', 'vat_no' => '114405515', 'series' => 'TPY', 'aa' => 1,
            'net_total' => 100, 'gross_total' => 124,
        ]);
        $invoice->lines()->create(['company_id' => $this->tenant->id, 'product_descr' => 'Υπηρεσία', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);

        return $invoice->fresh('lines');
    }

    public function test_blank_recipient_sends_to_the_customer(): void
    {
        $invoice = $this->issuedInvoice();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->mountAction('resend_email')
            ->setActionData(['to_override' => ''])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(fn (SendInvoiceEmail $job) => $job->invoice->is($invoice)
            && $job->trigger === 'manual'
            && $job->toOverride === null);
    }

    public function test_typed_recipient_sends_a_targeted_copy(): void
    {
        $invoice = $this->issuedInvoice();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->mountAction('resend_email')
            ->setActionData(['to_override' => 'logistis@example.gr'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Queue::assertPushed(fn (SendInvoiceEmail $job) => $job->invoice->is($invoice)
            && $job->trigger === 'manual'
            && $job->toOverride === 'logistis@example.gr');
    }

    public function test_action_is_visible_even_when_the_customer_has_no_email(): void
    {
        // The whole point of the custom field: reachable even when there is no
        // customer email to default to, so the operator can still send a copy.
        $invoice = $this->issuedInvoice(customerEmail: null);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('resend_email');
    }

    public function test_recipient_is_required_when_the_customer_has_no_email(): void
    {
        // No customer email → no default recipient → the field must be filled,
        // otherwise a blank submit would just log «no email» and send nothing.
        $invoice = $this->issuedInvoice(customerEmail: null);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->mountAction('resend_email')
            ->setActionData(['to_override' => ''])
            ->callMountedAction()
            ->assertHasActionErrors(['to_override']);

        Queue::assertNotPushed(SendInvoiceEmail::class);
    }

    public function test_action_is_hidden_on_a_draft(): void
    {
        // DOC-6: only an issued document may be emailed.
        $invoice = $this->issuedInvoice();
        $invoice->forceFill(['local_status' => 'draft'])->save();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('resend_email');
    }
}
