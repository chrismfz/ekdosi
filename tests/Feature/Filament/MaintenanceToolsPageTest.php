<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MaintenanceTools;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Εργαλεία» maintenance page runs safe per-tenant commands as buttons and
 * captures their output on the page.
 */
class MaintenanceToolsPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // authorize page access (View:MaintenanceTools)
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $this->tenant = Company::create([
            'name' => 'Tools OE', 'slug' => 'tools-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($this->tenant);
    }

    public function test_page_renders_and_exposes_the_command_buttons(): void
    {
        Livewire::test(MaintenanceTools::class)
            ->assertSuccessful()
            ->assertActionVisible('recompute_balances')
            ->assertActionVisible('refresh_vat_picture');
        // (mydata_preflight moved to the «Έλεγχος ρυθμίσεων» console tab.)
    }

    public function test_recompute_balances_button_runs_and_captures_output(): void
    {
        Livewire::test(MaintenanceTools::class)
            ->callAction('recompute_balances')
            ->assertHasNoActionErrors()
            ->assertSet('lastCommand', 'invoices:recompute-balances')
            ->assertSet('lastStatus', 'ok');
    }
}
