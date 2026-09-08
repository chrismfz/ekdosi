<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Companies list «Τρόπος αποστολής» column shows the FULL channel (provider +
 * environment) — «InvoSign — Δοκιμαστικό» / «myDATA — Παραγωγή» — not the raw
 * einvoice_provider value, and the myDATA column reads «ανάγνωση» for a provider
 * tenant (read path) rather than a bare «off».
 */
class CompaniesListChannelColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant(Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]));
    }

    public function test_provider_tenant_shows_the_friendly_channel_and_read_status(): void
    {
        Company::create([
            'name' => 'MyIP Networks OE', 'slug' => 'myip-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);

        Livewire::test(ListCompanies::class)
            ->assertSuccessful()
            ->assertSee('InvoSign — Δοκιμαστικό') // not «gr-provider»
            ->assertSee('ανάγνωση');               // myDATA read path, not «off»
    }

    public function test_mydata_tenant_shows_mydata_channel(): void
    {
        Company::create([
            'name' => 'NEXON OE', 'slug' => 'nexon-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'production',
        ]);

        Livewire::test(ListCompanies::class)
            ->assertSuccessful()
            ->assertSee('myDATA — Παραγωγή');
    }
}
