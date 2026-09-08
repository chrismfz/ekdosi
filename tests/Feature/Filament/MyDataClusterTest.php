<?php

namespace Tests\Feature\Filament;

use App\Filament\Clusters\MyDataCluster;
use App\Filament\Pages\MyDataConsole;
use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Pages\MyDataE3Overview;
use App\Filament\Pages\MyDataReconciliation;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «Κονσόλα myDATA» cluster groups the three live-AADE consoles under one nav
 * item. It's visible only when a member is accessible (canReadMyData + the
 * View:* perm), and the old console URLs redirect into the cluster's tabs.
 */
class MyDataClusterTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $mode = 'sandbox'): Company
    {
        return Company::create([
            'name' => 'C', 'slug' => 'c-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => $mode,
        ]);
    }

    private function actAsUser(Company $tenant): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $user;
    }

    #[Test]
    public function the_cluster_is_accessible_when_a_member_console_is(): void
    {
        Gate::before(fn () => true); // grant the View:* perms
        $this->actAsUser($this->tenant());

        $this->assertTrue(MyDataCluster::canAccess());
    }

    #[Test]
    public function the_cluster_is_hidden_when_no_member_is_accessible(): void
    {
        // No Gate::before → user lacks View:MyData* → every member canAccess() is false.
        $this->actAsUser($this->tenant());

        $this->assertFalse(MyDataCluster::canAccess());
    }

    #[Test]
    public function it_is_hidden_for_a_non_mydata_tenant_even_for_an_admin(): void
    {
        Gate::before(fn () => true);
        $this->actAsUser($this->tenant('off')); // canReadMyData() false

        $this->assertFalse(MyDataCluster::canAccess());
    }

    #[Test]
    public function exactly_the_three_admin_consoles_belong_to_the_cluster(): void
    {
        // The three live-AADE consoles are clustered…
        $this->assertSame(MyDataCluster::class, MyDataConsole::getCluster());
        $this->assertSame(MyDataCluster::class, MyDataConsoleExpenses::getCluster());
        $this->assertSame(MyDataCluster::class, MyDataE3Overview::getCluster());

        // …and the local (operator, no-AADE) reconciliation deliberately is NOT.
        $this->assertNull(MyDataReconciliation::getCluster());
    }

    #[Test]
    public function the_old_console_urls_redirect_into_the_cluster(): void
    {
        $tenant = $this->tenant();

        $this->get("/admin/{$tenant->slug}/my-data-console")
            ->assertRedirect("/admin/{$tenant->slug}/mydata/sales");
        $this->get("/admin/{$tenant->slug}/my-data-console-expenses")
            ->assertRedirect("/admin/{$tenant->slug}/mydata/expenses");
        $this->get("/admin/{$tenant->slug}/my-data-e3-overview")
            ->assertRedirect("/admin/{$tenant->slug}/mydata/e3");
    }
}
