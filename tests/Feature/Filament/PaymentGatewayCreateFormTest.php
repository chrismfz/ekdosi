<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentGatewayConnections\Pages\CreatePaymentGatewayConnection;
use App\Models\Company;
use App\Models\PaymentGatewayConnection;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: the per-gateway `config` section must actually bind the typed
 * values so `required()` fields (Eurobank Merchant ID + Shared Secret) validate
 * and SAVE — the operator reported «required» errors on a fully-filled form
 * (root cause: a reactive `->schema(fn (Get))` closure whose fields never
 * hydrated; now a static per-gateway schema toggled by visibility).
 */
class PaymentGatewayCreateFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $company = Company::create(['name' => 'Host', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);
        // canAccess() checks isSystemSuperAdmin() directly (not a Gate), so Gate::before
        // doesn't bypass it — the page won't mount without the real role.
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);
    }

    public function test_eurobank_config_saves_without_spurious_required_errors(): void
    {
        // Mimic the LIVE flow: pick the gateway first (->live() rebuilds the config
        // section), THEN type into the reactive per-gateway fields — the path the
        // operator hit, unlike fillForm() which sets the whole state at once.
        Livewire::test(CreatePaymentGatewayConnection::class)
            ->set('data.gateway', 'eurobank')
            ->set('data.label', 'Κάρτα')
            ->set('data.config.merchant_id', '0024000000')
            ->set('data.config.shared_secret', '6jLRuxSECRETvalue')
            ->set('data.config.lang', 'el')
            ->set('data.config.testmode', false)
            ->call('create')
            ->assertHasNoFormErrors();

        $conn = PaymentGatewayConnection::query()->firstOrFail();
        $this->assertSame('eurobank', $conn->gateway);
        $this->assertSame('0024000000', $conn->config['merchant_id']);
        $this->assertSame('6jLRuxSECRETvalue', $conn->config['shared_secret']);
        $this->assertNotNull($conn->company_id);
    }

    /**
     * The real guard for the browser bug: `->set('data.config.*')` above bypasses
     * the component binding, so it stayed green even when the config section was a
     * reactive `->schema(fn (Get))` closure whose fields never rendered/hydrated.
     * A STATIC per-gateway schema fixes it — assert the observable symptoms:
     *  - only the selected gateway's fields render, and
     *  - their `->default()`s are applied on selection (a reactively-BUILT field is
     *    added after `fill()`, so it gets NO default — testmode/lang came up blank,
     *    the tell-tale of the broken path).
     */
    public function test_selected_gateway_config_fields_render_with_defaults(): void
    {
        Livewire::test(CreatePaymentGatewayConnection::class)
            ->set('data.gateway', 'eurobank')
            ->assertSee('Merchant ID')
            ->assertDontSee('Λογαριασμοί προς εμφάνιση')   // manual's field is hidden
            ->assertSet('data.config.testmode', true)      // default applied…
            ->assertSet('data.config.lang', 'el')          // …only a mounted field gets it
            ->set('data.gateway', 'manual')
            ->assertSee('Λογαριασμοί προς εμφάνιση')
            ->assertDontSee('Merchant ID');
    }
}
