<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\McpTokens;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Τα κλειδιά MCP μου» — self-service minting of a tenant-bound MCP bearer token.
 * Proves: (a) company_admin auto-gets View:McpTokens while operator does not (a
 * token bypasses login + 2FA, so it is admin-only), (b) create mints a token
 * bound to the ACTIVE tenant and reveals the plaintext once, (c) the list + revoke
 * are scoped to the current user AND the current tenant — another tenant's (or
 * user's) token never surfaces and is never revocable here.
 */
class McpTokensPageTest extends TestCase
{
    use RefreshDatabase;

    private string $guard = 'web';

    private function makeCompany(): Company
    {
        return Company::create([
            'name' => 'MCP OE', 'slug' => 'mcp-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    // ── Permission wiring ───────────────────────────────────────────────────

    #[Test]
    public function company_admin_gets_the_page_permission_operator_does_not(): void
    {
        foreach (['View:McpTokens', 'ViewAny:Invoice', 'Create:Invoice'] as $n) {
            Permission::findOrCreate($n, $this->guard);
        }

        $company = $this->makeCompany();
        app(TenantRoleProvisioner::class)->ensureStandardRoles($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::where('name', TenantRoleProvisioner::ROLE_COMPANY_ADMIN)->where('company_id', $company->getKey())->first();
        $operator = Role::where('name', TenantRoleProvisioner::ROLE_OPERATOR)->where('company_id', $company->getKey())->first();

        $this->assertContains('View:McpTokens', $admin->permissions->pluck('name')->all(),
            'company_admin must be able to mint their own MCP tokens');
        $this->assertNotContains('View:McpTokens', $operator->permissions->pluck('name')->all(),
            'operator must NOT get MCP tokens by default (a token bypasses 2FA)');
    }

    #[Test]
    public function a_user_without_the_permission_cannot_access(): void
    {
        $company = $this->makeCompany();
        $user = User::create(['name' => 'Plain', 'email' => 'p-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);

        $this->assertFalse(McpTokens::canAccess());
    }

    // ── Page behaviour (permission bypassed) ────────────────────────────────

    private function actAsAuthorized(): array
    {
        Gate::before(fn () => true); // bypass View:McpTokens — behaviour, not gating
        $user = User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        $company = $this->makeCompany();
        $user->companies()->attach($company->id);
        Filament::setTenant($company);

        return [$user, $company];
    }

    #[Test]
    public function create_mints_a_tenant_bound_token_and_reveals_the_plaintext_once(): void
    {
        [$user, $company] = $this->actAsAuthorized();

        $component = Livewire::test(McpTokens::class)
            ->assertSuccessful()
            ->callAction('create', ['name' => 'laptop'])
            ->assertHasNoActionErrors();

        // The plaintext is revealed exactly once, on the component that minted it.
        $plain = $component->get('plainToken');
        $this->assertIsString($plain);
        $this->assertNotSame('', $plain);

        // Exactly one token, owned by this user, bound to THIS tenant.
        $this->assertSame(1, $user->tokens()->count());
        $token = $user->tokens()->first();
        $this->assertSame('laptop', $token->name);
        $this->assertContains('tenant:'.$company->getKey(), (array) $token->abilities);

        // The plaintext's hash matches the stored token (it is a real, usable key).
        $this->assertSame($token->token, hash('sha256', explode('|', $plain, 2)[1]));

        // And it shows up in the page's own listing.
        $rows = $component->instance()->tokens();
        $this->assertCount(1, $rows);
        $this->assertSame('laptop', $rows[0]['name']);
    }

    #[Test]
    public function an_empty_name_falls_back_to_a_slug_label(): void
    {
        [$user, $company] = $this->actAsAuthorized();

        Livewire::test(McpTokens::class)
            ->callAction('create', ['name' => '   '])
            ->assertHasNoActionErrors();

        $this->assertSame('mcp:'.$company->slug, $user->tokens()->first()->name);
    }

    #[Test]
    public function the_list_and_revoke_are_scoped_to_the_current_tenant(): void
    {
        [$user, $company] = $this->actAsAuthorized();

        // A token for THIS tenant, and one bound to a DIFFERENT tenant.
        $mine = $user->createToken('mine', ['tenant:'.$company->getKey()])->accessToken;
        $other = $user->createToken('other-tenant', ['tenant:999999'])->accessToken;

        $component = Livewire::test(McpTokens::class);

        // Only the current tenant's token is listed.
        $rows = $component->instance()->tokens();
        $this->assertCount(1, $rows);
        $this->assertSame('mine', $rows[0]['name']);

        // Revoking the other-tenant token from here is refused (isolation).
        $component->call('revoke', $other->getKey());
        $this->assertNotNull($user->tokens()->whereKey($other->getKey())->first());

        // Revoking my own current-tenant token works.
        $component->call('revoke', $mine->getKey());
        $this->assertNull($user->tokens()->whereKey($mine->getKey())->first());
    }

    #[Test]
    public function a_user_never_sees_another_users_tokens(): void
    {
        [$user, $company] = $this->actAsAuthorized();

        $other = User::create(['name' => 'Other', 'email' => 'o-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $other->companies()->attach($company->id);
        $other->createToken('theirs', ['tenant:'.$company->getKey()]);

        $rows = Livewire::test(McpTokens::class)->instance()->tokens();
        $this->assertCount(0, $rows, 'only the acting user\'s own tokens may appear');
    }
}
