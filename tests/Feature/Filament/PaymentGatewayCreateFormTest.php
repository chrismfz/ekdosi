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
 * Regression: the reactive per-gateway `config` section must actually bind the
 * typed values so `required()` fields (Eurobank Merchant ID + Shared Secret)
 * validate and SAVE — the operator reported «required» errors on a fully-filled
 * form.
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
}
