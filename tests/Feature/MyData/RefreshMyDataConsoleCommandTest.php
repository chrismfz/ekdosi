<?php

namespace Tests\Feature\MyData;

use App\Console\Commands\RefreshMyDataConsole;
use App\Filament\Pages\MyDataConsole;
use App\Models\Company;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scheduled `mydata:refresh-console` warmer — a thin, resilient loop over
 * MyDataConsoleRefresh::refreshAll() per myDATA-readable tenant. The four-pull
 * orchestration itself is covered by MyDataConsoleRefreshTest; here we assert the
 * command's own behaviour (tenant resolution, exit codes, resilience).
 */
class RefreshMyDataConsoleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RefreshMyDataConsole::$testHandler = null;
        parent::tearDown();
    }

    private function sandboxTenant(): Company
    {
        return Company::create([
            'name' => 'Console OE', 'slug' => 'con-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '801280908', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
    }

    public function test_no_mydata_readable_tenant_is_a_clean_success(): void
    {
        // A non-myDATA tenant (provider none) → nothing to refresh, exit 0.
        Company::create([
            'name' => 'EE OU', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE',
            'einvoice_provider' => 'none', 'mydata_mode' => 'off',
        ]);

        $this->artisan('mydata:refresh-console')
            ->expectsOutputToContain('No matching myDATA-readable tenant')
            ->assertExitCode(0);
    }

    public function test_unknown_tenant_arg_is_a_clean_success(): void
    {
        $this->artisan('mydata:refresh-console', ['--tenant' => 'does-not-exist'])
            ->assertExitCode(0);
    }

    public function test_warms_the_sales_cache_for_a_tenant(): void
    {
        $tenant = $this->sandboxTenant();

        // One valid (empty) TransmittedDocs page → the Πωλήσεις step succeeds and
        // seeds the console cache; the later steps drain the queue and fail
        // gracefully (resilient — the command must not crash).
        RefreshMyDataConsole::$testHandler = new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc/>
</RequestedDoc>
XML),
        ]);

        $this->artisan('mydata:refresh-console', ['--gap' => 0])
            ->expectsOutputToContain($tenant->slug)
            ->run();

        $this->assertNotNull(
            MyDataConsole::lastFetchAt($tenant->id),
            'the sales console cache was seeded by the command',
        );
    }

    public function test_does_not_crash_on_empty_pulls(): void
    {
        $tenant = $this->sandboxTenant();

        // An empty mock queue makes each AADE call throw OutOfBoundsException
        // (a RuntimeException → step() classifies it «warn»). This is NOT the
        // production "AADE down" path (see the ConnectException test below); it
        // only proves the loop completes + reports without crashing on empties.
        RefreshMyDataConsole::$testHandler = new MockHandler;

        $this->artisan('mydata:refresh-console', ['--gap' => 0])
            ->expectsOutputToContain($tenant->slug)
            ->assertExitCode(0);
    }

    public function test_real_connection_fault_exits_failure(): void
    {
        $tenant = $this->sandboxTenant();

        // A genuine transport failure: Guzzle ConnectException → firebed wraps it
        // as MyDataConnectionException (extends Exception, NOT RuntimeException) →
        // step() classifies it «error» → the command must exit FAILURE so the
        // scheduler health surfaces a truly-down AADE (covers the error branch).
        RefreshMyDataConsole::$testHandler = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'https://mydata.aade.gr')),
        ]);

        $this->artisan('mydata:refresh-console', ['--gap' => 0])
            ->expectsOutputToContain($tenant->slug)
            ->assertExitCode(1);
    }
}
