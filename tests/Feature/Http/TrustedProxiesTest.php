<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Who may tell us the client IP / scheme (TRUSTED_PROXIES, default `local` =
 * this box — the CFM edge proxies from loopback or the server's own IP). Read
 * from config per request, so a value set only in .env is honoured (it used to
 * be read in bootstrap, before .env loaded, and silently ignored).
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__proxy-probe', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'url' => url('/x'),
        ]));
    }

    protected function tearDown(): void
    {
        // Symfony keeps the proxy list in a static that outlives the test.
        Request::setTrustedProxies([], -1);

        parent::tearDown();
    }

    private function probe(string $remoteAddr, array $headers = [], array $server = [], string $url = '/__proxy-probe')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr] + $server)->getJson($url, $headers);
    }

    public function test_default_trusts_a_loopback_edge(): void
    {
        $this->assertSame('local', config('trustedproxy.proxies'));

        $this->probe('127.0.0.1', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);

        $this->probe('::1', ['X-Forwarded-For' => '203.0.113.10'])->assertJson(['ip' => '203.0.113.10']);
        $this->probe('::ffff:127.0.0.1', ['X-Forwarded-For' => '203.0.113.11'])->assertJson(['ip' => '203.0.113.11']);
    }

    public function test_default_trusts_an_edge_dialling_the_servers_own_address(): void
    {
        // A same-host edge that connects to the public IP arrives FROM that IP.
        $this->probe('198.51.100.20', ['X-Forwarded-For' => '203.0.113.12'], ['SERVER_ADDR' => '198.51.100.20'])
            ->assertJson(['ip' => '203.0.113.12']);
    }

    public function test_a_dual_stack_server_address_matches_its_ipv4_peer(): void
    {
        $this->probe('198.51.100.20', ['X-Forwarded-For' => '203.0.113.14'], ['SERVER_ADDR' => '::ffff:198.51.100.20'])
            ->assertJson(['ip' => '203.0.113.14']);
    }

    public function test_default_trusts_the_servers_interface_addresses(): void
    {
        $own = collect(TrustProxies::resolve('local', Request::create('/', server: ['REMOTE_ADDR' => '198.51.100.250'])))
            ->first(fn (string $ip) => ! str_contains($ip, '/') && ! str_starts_with($ip, '::') && $ip !== '127.0.0.1');

        if ($own === null) {
            $this->markTestSkipped('net_get_interfaces() found no non-loopback address on this host');
        }

        $this->probe($own, ['X-Forwarded-For' => '203.0.113.13'])->assertJson(['ip' => '203.0.113.13']);
    }

    public function test_a_forged_header_from_outside_is_ignored(): void
    {
        $this->probe('198.51.100.7', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '198.51.100.7', 'secure' => false]);
    }

    public function test_the_client_is_the_rightmost_untrusted_hop(): void
    {
        // A client-forged entry the edge appended to is not taken at face value.
        $this->probe('127.0.0.1', ['X-Forwarded-For' => '10.9.9.9, 203.0.113.9'])
            ->assertJson(['ip' => '203.0.113.9']);
    }

    public function test_forwarded_host_and_port_are_not_trusted(): void
    {
        $this->probe('127.0.0.1', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Port' => '8443',
        ])->assertJson(['host' => 'localhost', 'url' => 'https://localhost/x']);
    }

    public function test_none_trusts_nobody(): void
    {
        config(['trustedproxy.proxies' => 'none']);

        $this->probe('127.0.0.1', ['X-Forwarded-For' => '203.0.113.9'])->assertJson(['ip' => '127.0.0.1']);
    }

    public function test_an_explicit_list_and_the_wildcard(): void
    {
        config(['trustedproxy.proxies' => '192.0.2.0/24, 198.51.100.5']);
        $this->probe('192.0.2.44', ['X-Forwarded-For' => '203.0.113.1'])->assertJson(['ip' => '203.0.113.1']);
        $this->probe('198.51.100.5', ['X-Forwarded-For' => '203.0.113.2'])->assertJson(['ip' => '203.0.113.2']);
        $this->probe('127.0.0.1', ['X-Forwarded-For' => '203.0.113.3'])
            ->assertJson(['ip' => '127.0.0.1']);   // an explicit list replaces `local`…

        config(['trustedproxy.proxies' => 'local,192.0.2.0/24']);
        $this->probe('127.0.0.1', ['X-Forwarded-For' => '203.0.113.4'])
            ->assertJson(['ip' => '203.0.113.4']);   // …unless it names it

        config(['trustedproxy.proxies' => '*']);
        $this->probe('198.51.100.99', ['X-Forwarded-For' => '203.0.113.5'])->assertJson(['ip' => '203.0.113.5']);
    }
}
