<?php

namespace Tests\Feature\MyData;

use App\Console\Commands\RefreshMyDataConsole;
use App\Filament\Pages\MyDataConsole;
use App\Models\Company;
use GuzzleHttp\Handler\MockHandler;
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

    public function test_completes_resiliently_when_aade_is_unreachable(): void
    {
        $tenant = $this->sandboxTenant();

        // Empty mock → every AADE pull throws a transient (RuntimeException-family)
        // error; refreshAll isolates each step as a warn, so the command completes
        // WITHOUT crashing and exits 0 (transient AADE trouble is not a hard fault —
        // the stale-data banner is the operator's signal that the cache is old).
        RefreshMyDataConsole::$testHandler = new MockHandler;

        $this->artisan('mydata:refresh-console', ['--gap' => 0])
            ->expectsOutputToContain($tenant->slug)
            ->assertExitCode(0);
    }
}
