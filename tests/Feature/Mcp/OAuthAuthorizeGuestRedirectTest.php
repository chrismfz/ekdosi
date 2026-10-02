<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Connecting the MCP connector from a browser with no panel session hit
 * GET /oauth/authorize as a guest → the framework redirected to route('login'),
 * which doesn't exist here → «Route [login] not defined» → 500 (seen on
 * invoicer.myip.gr, 2026-10-02). Guests must land on the panel login instead.
 */
class OAuthAuthorizeGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $keyDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Passport needs its RSA key pair to build the authorization/resource
        // servers — without it every OAuth/MCP request is a 500 BEFORE the auth
        // check. CI has no storage/oauth-*.key, so generate a throwaway pair once
        // (never touching a host's real keys) and point Passport at it.
        if (self::$keyDir === null) {
            self::$keyDir = sys_get_temp_dir().'/ekdosi-passport-test-'.getmypid();
            @mkdir(self::$keyDir, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            file_put_contents(self::$keyDir.'/oauth-private.key', $private);
            file_put_contents(self::$keyDir.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
            chmod(self::$keyDir.'/oauth-private.key', 0600);
            chmod(self::$keyDir.'/oauth-public.key', 0600);
        }
        Passport::loadKeysFrom(self::$keyDir);
    }

    protected function tearDown(): void
    {
        Passport::$keyPath = null;   // don't leak the throwaway keys into other tests
        parent::tearDown();
    }

    private function authorizeUrl(): string
    {
        // A real authorization-code client: Passport validates the client BEFORE
        // the login check (an unknown client_id is a 401, never the login path).
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('MCP test', ['https://example.com/cb']);

        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://example.com/cb',
            'state' => 's',
        ]);
    }

    public function test_a_guest_on_oauth_authorize_is_sent_to_the_panel_login_not_a_500(): void
    {
        $this->get($this->authorizeUrl())
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_the_login_returns_to_the_authorize_url_afterwards(): void
    {
        $url = $this->authorizeUrl();
        $this->get($url);

        // Same path + same OAuth params (the framework normalises the query order).
        $intended = parse_url((string) session('url.intended'));
        parse_str($intended['query'] ?? '', $got);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $want);
        $this->assertSame('/oauth/authorize', $intended['path'] ?? null);
        $this->assertEquals($want, $got);
    }

    public function test_an_unauthenticated_mcp_call_is_a_json_401_whatever_the_accept_header(): void
    {
        // OAuth discovery starts from a 401 + WWW-Authenticate — a redirect to the
        // panel login (the guest redirect above) would stall the MCP client.
        foreach (['application/json, text/event-stream', 'text/event-stream, application/json'] as $accept) {
            $this->withHeaders(['Accept' => $accept, 'Content-Type' => 'application/json'])
                ->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
                ->assertStatus(401)
                ->assertHeader('WWW-Authenticate');
        }
    }
}
