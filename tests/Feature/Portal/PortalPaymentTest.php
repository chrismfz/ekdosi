<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Portal «Πλήρωσε» (B0b): grant-scoped throughout — a login only pays for a
 * (company, customer) it holds an active grant to, and only sees its own intent.
 * Starting a payment creates a PENDING intent (the browser never settles money).
 */
class PortalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['name' => 'T', 'slug' => 'pay-'.uniqid(), 'country_code' => 'GR']);
    }

    private function customer(Company $t): Customer
    {
        return Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '090000045']);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'), 'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    private function grant(CustomerUser $l, Customer $c): void
    {
        CustomerUserAccess::create([
            'customer_user_id' => $l->id, 'company_id' => $c->company_id,
            'customer_id' => $c->id, 'role' => CustomerUserAccess::ROLE_OWNER, 'granted_at' => now(),
        ]);
    }

    private function method(Company $t, bool $active = true): PaymentGatewayConnection
    {
        return PaymentGatewayConnection::create([
            'company_id' => $t->id, 'gateway' => 'manual', 'label' => 'Κατάθεση',
            'is_active' => $active, 'sort' => 0, 'config' => ['instructions' => 'ref'],
        ]);
    }

    public function test_chosen_method_is_preserved_when_validation_bounces_back(): void
    {
        // A bad amount bounces back with old input — the picked method must stay
        // selected (native radios must honour old('connection_id'), not reset to
        // the first). Two methods so «first» ≠ «chosen».
        $t = $this->company();
        $c = $this->customer($t);
        $this->method($t);                 // id 1 (first)
        $second = $this->method($t);       // id 2 (the one we pick)
        $login = $this->login();
        $this->grant($login, $c);

        $this->actingAs($login, 'portal')
            ->from("/user/pay/{$c->id}")
            ->post("/user/pay/{$c->id}", ['connection_id' => $second->id, 'amount' => '0'])
            ->assertRedirect("/user/pay/{$c->id}");   // amount<0.01 → bounce

        $html = $this->actingAs($login, 'portal')->get("/user/pay/{$c->id}")->getContent();
        // The radio for the CHOSEN method carries `checked`; the first does not.
        $this->assertMatchesRegularExpression(
            '/value="'.$second->id.'"[^>]*\bchecked\b/s', $html,
            'chosen method radio should stay checked after a validation bounce',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="1"[^>]*\bchecked\b/s', $html,
            'first method should not be checked when another was chosen',
        );
    }

    public function test_create_page_renders_for_a_granted_company(): void
    {
        $t = $this->company();
        $c = $this->customer($t);
        $this->method($t);
        $login = $this->login();
        $this->grant($login, $c);

        $this->actingAs($login, 'portal')->get("/user/pay/{$c->id}")
            ->assertOk()->assertSee('Πληρωμή')->assertSee('Κατάθεση')
            // A NATIVE radio must render — not the flux:radio.group «cards» variant,
            // whose <ui-radio> web-component shows nothing until the Vite/Flux build
            // is served, so on a box without `npm run build` the method list was
            // blank and the customer could not pay.
            ->assertSee('type="radio"', false)
            ->assertSee('name="connection_id"', false);
    }

    public function test_create_page_404_for_a_non_granted_customer(): void
    {
        $t = $this->company();
        $c = $this->customer($t);      // exists but NOT granted to this login
        $login = $this->login();

        $this->actingAs($login, 'portal')->get("/user/pay/{$c->id}")->assertStatus(404);
    }

    public function test_store_with_an_invoice_target_records_it_on_the_intent(): void
    {
        $t = $this->company();
        $c = $this->customer($t);
        $method = $this->method($t);
        $login = $this->login();
        $this->grant($login, $c);

        // A payable (credit-term, issued, open) invoice the customer can target.
        $type = InvoiceType::create(['company_id' => $t->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $credit = PaymentMethod::create(['company_id' => $t->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30]);
        $inv = Invoice::create([
            'company_id' => $t->id, 'invcode' => 'ΤΙΜ7', 'code' => 7, 'invoice_type_id' => $type->id,
            'customer_id' => $c->id, 'issued_at' => now()->subDay(), 'local_status' => 'active', 'payment_method_id' => $credit->id,
        ]);
        $inv->forceFill(['net_total' => 40, 'gross_total' => 40])->save();

        // The pay page renders the invoice selector with this document as a choice.
        $this->actingAs($login, 'portal')->get("/user/pay/{$c->id}")
            ->assertOk()->assertSee('Τι πληρώνεις;')->assertSee('ΤΙΜ7');

        $this->actingAs($login, 'portal')->post("/user/pay/{$c->id}", [
            'connection_id' => $method->id, 'amount' => '40.00', 'invoice_id' => $inv->id,
        ])->assertRedirect();

        $intent = PaymentIntent::query()->where('customer_id', $c->id)->firstOrFail();
        $this->assertSame($inv->id, $intent->invoice_id);
        $this->assertSame('invoice', $intent->purpose);
    }

    public function test_store_rejects_an_invoice_that_is_not_the_customers(): void
    {
        $t = $this->company();
        $c = $this->customer($t);
        $method = $this->method($t);
        $login = $this->login();
        $this->grant($login, $c);

        // An invoice id that is not one of this customer's payable documents → 404.
        $this->actingAs($login, 'portal')->post("/user/pay/{$c->id}", [
            'connection_id' => $method->id, 'amount' => '10.00', 'invoice_id' => 999999,
        ])->assertStatus(404);
    }

    public function test_store_creates_a_pending_intent_and_redirects(): void
    {
        $t = $this->company();
        $c = $this->customer($t);
        $method = $this->method($t);
        $login = $this->login();
        $this->grant($login, $c);

        $res = $this->actingAs($login, 'portal')->post("/user/pay/{$c->id}", [
            'connection_id' => $method->id, 'amount' => '50.00',
        ]);

        $intent = PaymentIntent::query()->where('customer_id', $c->id)->first();
        $this->assertNotNull($intent);
        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->status);
        $this->assertSame('50.00', (string) $intent->amount);
        $res->assertRedirect(route('portal.payment.show', $intent->id));
    }

    public function test_paying_for_one_of_several_granted_customers_targets_the_right_one(): void
    {
        // A login granted to TWO customers in the SAME company must pay for the
        // customer whose button was clicked, not «the first grant».
        $t = $this->company();
        $a = Customer::create(['company_id' => $t->id, 'name' => 'A', 'afm' => '1']);
        $b = Customer::create(['company_id' => $t->id, 'name' => 'B', 'afm' => '2']);
        $method = $this->method($t);
        $login = $this->login();
        $this->grant($login, $a);
        $this->grant($login, $b);

        $this->actingAs($login, 'portal')->post("/user/pay/{$b->id}", [
            'connection_id' => $method->id, 'amount' => '10.00',
        ])->assertRedirect();

        $this->assertSame(0, PaymentIntent::where('customer_id', $a->id)->count());
        $this->assertSame(1, PaymentIntent::where('customer_id', $b->id)->count());
    }

    public function test_show_404_for_another_customers_intent(): void
    {
        $t = $this->company();
        $mine = $this->customer($t);
        $other = Customer::create(['company_id' => $t->id, 'name' => 'Other', 'afm' => '1']);
        $login = $this->login();
        $this->grant($login, $mine);   // granted to Mine, not Other

        $otherIntent = PaymentIntent::create([
            'company_id' => $t->id, 'customer_id' => $other->id, 'gateway' => 'manual',
            'amount' => 10, 'currency' => 'EUR', 'status' => 'pending', 'reference' => 'ΠΛ-X',
        ]);

        $this->actingAs($login, 'portal')->get("/user/payment/{$otherIntent->id}")->assertStatus(404);
    }

    public function test_pay_requires_portal_auth(): void
    {
        $t = $this->company();
        $c = $this->customer($t);
        $this->get("/user/pay/{$c->id}")->assertRedirect(route('portal.login'));
    }
}
