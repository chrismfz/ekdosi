<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ProviderConsole;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P4: the «Πάροχος» console renders the readiness preflight for a provider tenant
 * and is hidden for non-provider tenants.
 */
class ProviderConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
    }

    public function test_renders_for_a_provider_tenant(): void
    {
        Filament::setTenant(Company::create([
            'name' => 'Provider', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
            'einvoice_provider_config' => ['demo_base_url' => 'https://demo', 'demo_token' => 'T'],
        ]));

        $this->assertTrue(ProviderConsole::canAccess());

        Livewire::test(ProviderConsole::class)
            ->assertSuccessful()
            ->assertSee('Ετοιμότητα παρόχου');
    }

    public function test_hidden_for_a_non_provider_tenant(): void
    {
        Filament::setTenant(Company::create([
            'name' => 'myDATA', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]));

        $this->assertFalse(ProviderConsole::canAccess());
    }
}
