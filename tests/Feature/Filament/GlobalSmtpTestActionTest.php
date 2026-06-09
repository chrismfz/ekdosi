<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The super-admin-only «Δοκιμή global SMTP (.env)» header action on the Companies
 * list — sends a probe through the global mailer, independent of any tenant.
 */
class GlobalSmtpTestActionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Company $host;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // reach the page; the action's own super_admin gate still applies
        $this->actor = User::create([
            'name' => 'Actor', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($this->actor);
        $this->host = Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actor->companies()->attach($this->host->id);
        Filament::setTenant($this->host);
    }

    public function test_super_admin_can_send_a_global_probe(): void
    {
        // In-memory transport captures the raw Mail::html send (Mail::fake only
        // records Mailables, not raw sends).
        config(['mail.default' => 'array']);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->actor, $this->host);

        Livewire::test(ListCompanies::class)
            ->callAction('test_global_smtp', data: ['to' => 'probe@example.gr'])
            ->assertHasNoActionErrors();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('probe@example.gr', $messages[0]->getOriginalMessage()->toString());
    }

    public function test_action_is_hidden_for_non_super_admin(): void
    {
        // No super_admin role assigned → the action must not be visible.
        Livewire::test(ListCompanies::class)
            ->assertActionHidden('test_global_smtp');
    }

    public function test_non_super_admin_attempt_sends_nothing(): void
    {
        // The real security boundary: ->authorize() is enforced on mount, and the
        // in-action recheck backs it up — a non-super_admin captures no message.
        config(['mail.default' => 'array']);

        try {
            Livewire::test(ListCompanies::class)
                ->callAction('test_global_smtp', data: ['to' => 'probe@example.gr']);
        } catch (\Throwable) {
            // An unauthorized mount may halt/throw — either way, nothing is sent.
        }

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }
}
