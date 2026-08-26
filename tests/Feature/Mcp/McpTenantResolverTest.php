<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Support\McpTenantResolver;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The security-critical piece of the external MCP surface: WHICH company an
 * external caller acts as is decided SERVER-SIDE from the token, never from the
 * model. These lock the resolution rules so a cross-tenant read stays
 * structurally impossible on this channel too.
 */
class McpTenantResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
        ]);
    }

    private function makeCompany(string $slug): Company
    {
        return Company::create(['name' => strtoupper($slug), 'slug' => $slug.'-'.uniqid(), 'country_code' => 'GR']);
    }

    public function test_single_company_user_resolves_without_a_binding(): void
    {
        $user = $this->makeUser();
        $company = $this->makeCompany('solo');
        $user->companies()->attach($company->id);

        [$resolved, $error] = app(McpTenantResolver::class)->resolve($user);

        $this->assertNull($error);
        $this->assertNotNull($resolved);
        $this->assertSame($company->id, $resolved->id);
    }

    public function test_multi_company_user_without_a_binding_is_refused(): void
    {
        $user = $this->makeUser();
        $user->companies()->attach($this->makeCompany('a')->id);
        $user->companies()->attach($this->makeCompany('b')->id);

        [$resolved, $error] = app(McpTenantResolver::class)->resolve($user);

        $this->assertNull($resolved);
        $this->assertNotNull($error);
    }

    public function test_token_binding_selects_that_company(): void
    {
        $user = $this->makeUser();
        $a = $this->makeCompany('a');
        $b = $this->makeCompany('b');
        $user->companies()->attach([$a->id, $b->id]);

        // Bind the token to company B (the ability McpToken mints).
        $token = $user->createToken('mcp', ['tenant:'.$b->id]);
        $user->withAccessToken($token->accessToken);

        [$resolved, $error] = app(McpTenantResolver::class)->resolve($user);

        $this->assertNull($error);
        $this->assertSame($b->id, $resolved->id);
    }

    public function test_binding_to_a_foreign_company_is_refused(): void
    {
        $user = $this->makeUser();
        $mine = $this->makeCompany('mine');
        $foreign = $this->makeCompany('foreign'); // user is NOT attached to it
        $user->companies()->attach($mine->id);

        $token = $user->createToken('mcp', ['tenant:'.$foreign->id]);
        $user->withAccessToken($token->accessToken);

        [$resolved, $error] = app(McpTenantResolver::class)->resolve($user);

        $this->assertNull($resolved);
        $this->assertNotNull($error);
    }
}
