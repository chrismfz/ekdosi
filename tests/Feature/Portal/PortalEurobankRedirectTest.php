<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The hosted-gateway bounce page (B1): a pending Eurobank intent renders the
 * SIGNED auto-submitting vPOS form — but only through an ACTIVE method (a disabled
 * one falls back to the status page, never a live payment form) and only while the
 * intent is pending. Grant-scoped like the rest of the portal.
 */
class PortalEurobankRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Company $t;

    private Customer $customer;

    private CustomerUser $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Company::create(['name' => 'T', 'slug' => 'ebrd-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->t->id, 'name' => 'C', 'afm' => '090000045']);
        $this->login = CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'), 'status' => CustomerUser::STATUS_ACTIVE,
        ]);
        CustomerUserAccess::create([
            'customer_user_id' => $this->login->id, 'company_id' => $this->t->id,
            'customer_id' => $this->customer->id, 'role' => CustomerUserAccess::ROLE_OWNER, 'granted_at' => now(),
        ]);
    }

    private function connection(bool $active = true): PaymentGatewayConnection
    {
        return PaymentGatewayConnection::create([
            'company_id' => $this->t->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => $active, 'sort' => 0,
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => 'SECRET', 'testmode' => true],
        ]);
    }

    private function intent(PaymentGatewayConnection $conn, string $status = PaymentIntent::STATUS_PENDING): PaymentIntent
    {
        return PaymentIntent::create([
            'company_id' => $this->t->id, 'customer_id' => $this->customer->id, 'gateway' => 'eurobank',
            'payment_gateway_connection_id' => $conn->id, 'amount' => 40, 'currency' => 'EUR',
            'status' => $status, 'reference' => 'ΠΛ-RD-'.random_int(1000, 9999),
        ]);
    }

    public function test_pending_intent_renders_the_signed_auto_submit_form(): void
    {
        $intent = $this->intent($this->connection());

        $this->actingAs($this->login, 'portal')
            ->get(route('portal.payment.redirect', $intent->id))
            ->assertOk()
            ->assertSee('vpos-form')
            ->assertSee('cardlink.gr')            // test endpoint action
            ->assertSee((string) $intent->id);    // orderid hidden field
    }

    public function test_a_disabled_method_falls_back_to_the_status_page(): void
    {
        // Method disabled AFTER the intent was created — must not auto-submit a
        // live payment form through it.
        $intent = $this->intent($this->connection(active: false));

        $this->actingAs($this->login, 'portal')
            ->get(route('portal.payment.redirect', $intent->id))
            ->assertRedirect(route('portal.payment.show', $intent->id));
    }

    public function test_a_settled_intent_does_not_re_submit(): void
    {
        $intent = $this->intent($this->connection(), status: PaymentIntent::STATUS_SETTLED);

        $this->actingAs($this->login, 'portal')
            ->get(route('portal.payment.redirect', $intent->id))
            ->assertRedirect(route('portal.payment.show', $intent->id));
    }

    public function test_redirect_page_404_for_another_logins_intent(): void
    {
        $intent = $this->intent($this->connection());
        $stranger = CustomerUser::factory()->create(['status' => CustomerUser::STATUS_ACTIVE]);

        $this->actingAs($stranger, 'portal')
            ->get(route('portal.payment.redirect', $intent->id))
            ->assertStatus(404);
    }
}
