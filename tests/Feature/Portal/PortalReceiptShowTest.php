<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The portal's read-only «informal receipt» view of a payment / έμβασμα, and the
 * statement's links + reconciling totals footer. Same fail-closed grant boundary
 * as the document view (a login sees a payment ONLY through an active grant).
 */
class PortalReceiptShowTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'NEXON', 'slug' => 'rcpt-'.uniqid(), 'afm' => '801280908',
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    private function grant(CustomerUser $login, Customer $customer): void
    {
        CustomerUserAccess::create([
            'customer_user_id' => $login->id,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'role' => CustomerUserAccess::ROLE_OWNER,
            'granted_at' => now(),
        ]);
    }

    private function type(Company $t): InvoiceType
    {
        return InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
    }

    private function invoice(Company $t, InvoiceType $it, Customer $c, string $invcode, int $code): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $t->id, 'invoice_type_id' => $it->id, 'customer_id' => $c->id,
            'invcode' => $invcode, 'code' => $code, 'issued_at' => now(),
        ]);
        $inv->forceFill(['local_status' => 'active', 'mydata_state' => 'VALID', 'net_total' => 100, 'gross_total' => 124])->save();

        return $inv;
    }

    public function test_single_payment_receipt_renders_with_provenance(): void
    {
        $t = $this->company();
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $pay = Payment::create([
            'company_id' => $t->id, 'customer_id' => $cust->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 50, 'pay_date' => now(),
            'transaction_id' => 'TXN-ABC-123', 'notes' => 'Μετρητά στο ταμείο',
        ]);

        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get("/user/receipt/{$pay->id}")
            ->assertOk()
            ->assertSee('TXN-ABC-123')
            ->assertSee('Μετρητά στο ταμείο')
            ->assertSee('Χειροκίνητα');   // channelLabel() for a non-gateway payment
    }

    public function test_grouped_remittance_reexpands_by_reference(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $inv = $this->invoice($t, $it, $cust, 'ΤΠΥ6665', 6665);

        // Two payments sharing a reference = one έμβασμα: one hits an invoice, one lands on-account.
        $onInvoice = Payment::create([
            'company_id' => $t->id, 'customer_id' => $cust->id, 'invoice_id' => $inv->id,
            'kind' => 'payment', 'amount' => 124, 'pay_date' => now(), 'reference' => 'ΠΛ-260906-9B9443',
        ]);
        Payment::create([
            'company_id' => $t->id, 'customer_id' => $cust->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 26, 'pay_date' => now(), 'reference' => 'ΠΛ-260906-9B9443',
        ]);

        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get("/user/receipt/{$onInvoice->id}")
            ->assertOk()
            ->assertSee('ΠΛ-260906-9B9443')
            ->assertSee('ΤΠΥ6665')                                  // the settled invoice, linked
            ->assertSee("/user/document/{$inv->id}\"", false)
            ->assertSee('150,00');                                  // group total 124 + 26
    }

    public function test_receipt_404_for_a_non_granted_payment(): void
    {
        $t = $this->company();
        $mine = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $other = Customer::create(['company_id' => $t->id, 'name' => 'Other', 'afm' => '2']);
        $otherPay = Payment::create([
            'company_id' => $t->id, 'customer_id' => $other->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 10, 'pay_date' => now(),
        ]);

        $login = $this->login();
        $this->grant($login, $mine);   // granted to Mine, NOT Other

        $this->actingAs($login, 'portal')->get("/user/receipt/{$otherPay->id}")->assertStatus(404);
    }

    public function test_receipt_requires_portal_auth(): void
    {
        $this->get('/user/receipt/1')
            ->assertRedirect(route('portal.login'))
            ->assertSessionHas('url.intended', url('/user/receipt/1'));
    }

    public function test_statement_links_payments_and_shows_totals_footer(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $this->invoice($t, $it, $cust, 'ΤΠΥ6661', 6661);
        $pay = Payment::create([
            'company_id' => $t->id, 'customer_id' => $cust->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 20, 'pay_date' => now(),
        ]);

        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get('/user/statement')
            ->assertOk()
            ->assertSee("/user/receipt/{$pay->id}\"", false)        // payment row is a link
            ->assertSee('Σύνολο πληρωμών');                          // reconciling totals footer
    }
}
