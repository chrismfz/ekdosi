<?php

namespace Tests\Feature\Support;

use App\Filament\Clusters\SupportCluster;
use App\Filament\Resources\TicketDepartments\TicketDepartmentResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Πυλώνας E — the whole Support pillar is invisible unless the tenant has it
 * switched on (`companies.support_enabled`, default off): the Support Cluster,
 * the operator ticket resource, and the department config all gate on
 * `hasSupport()`. This is the «κρύψιμο τελείως» the operator asked for.
 */
class SupportPillarGateTest extends TestCase
{
    use RefreshDatabase;

    private function bootPanelFor(Company $company): void
    {
        Gate::before(fn () => true); // super_admin: only the pillar flag should gate here
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($company);
    }

    private function company(bool $support): Company
    {
        return Company::create([
            'name' => 'Sup', 'slug' => 's-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'support_enabled' => $support,
        ]);
    }

    public function test_support_screens_are_hidden_when_the_pillar_is_off(): void
    {
        $this->bootPanelFor($this->company(support: false));

        $this->assertFalse(TicketResource::canAccess(), 'Τα αιτήματα δεν πρέπει να φαίνονται με ανενεργό pillar');
        $this->assertFalse(TicketDepartmentResource::canAccess(), 'Τα τμήματα δεν πρέπει να φαίνονται');
        $this->assertFalse(SupportCluster::canAccess(), 'Το cluster «Υποστήριξη» πρέπει να είναι κρυμμένο');
    }

    public function test_support_screens_appear_when_the_pillar_is_on(): void
    {
        $this->bootPanelFor($this->company(support: true));

        $this->assertTrue(TicketResource::canAccess());
        $this->assertTrue(TicketDepartmentResource::canAccess());
        $this->assertTrue(SupportCluster::canAccess());
    }
}
