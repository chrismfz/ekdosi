<?php

namespace Tests\Feature\Delivery;

use App\Console\Commands\DeliveryFetchInbound;
use App\Models\Company;
use App\Services\Delivery\InboundDeliveryFetcher;
use App\Services\Delivery\InboundFetchResult;
use Carbon\Carbon;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Slice 4a — the command's tenant-resolution glue, exercised WITHOUT touching
 * AADE (no myDATA-readable tenant / an unknown tenant → the command short-circuits
 * before any RequestDocs call). The network path itself is covered by
 * InboundDeliveryFetcherTest; the transient-failure resilience is covered here via
 * the `fetcherFor()` seam.
 */
class DeliveryFetchInboundCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_warns_and_succeeds_when_no_mydata_readable_tenant(): void
    {
        $this->artisan('delivery:fetch-inbound')
            ->expectsOutputToContain('No matching myDATA-readable tenant')
            ->assertExitCode(0);
    }

    public function test_reports_unknown_tenant_and_makes_no_aade_call(): void
    {
        $this->artisan('delivery:fetch-inbound', ['--tenant' => 'does-not-exist'])
            ->expectsOutputToContain("Tenant 'does-not-exist' not found.")
            ->assertExitCode(0);
    }

    public function test_transient_fetch_failure_is_logged_and_run_still_succeeds(): void
    {
        $tenant = $this->makeReadableTenant();

        Log::spy();

        // A command whose fetcher raises a transient AADE error (firebed's
        // MyDataConnectionException extends \Exception → the command's Throwable
        // branch). The read-only poll must LOG it and STILL exit 0 — never page.
        $command = new class extends DeliveryFetchInbound
        {
            protected function fetcherFor(Company $tenant): InboundDeliveryFetcher
            {
                return new class($tenant) extends InboundDeliveryFetcher
                {
                    public function fetch(?Carbon $from = null, ?Carbon $to = null, bool $dryRun = false): InboundFetchResult
                    {
                        throw new MyDataConnectionException;
                    }
                };
            }
        };
        $command->setLaravel($this->app);

        $output = new BufferedOutput;
        $exit = $command->run(new ArrayInput(['--tenant' => $tenant->slug]), $output);

        $this->assertSame(0, $exit, 'a transient per-tenant fetch failure must not fail the read-only poll');
        $this->assertStringContainsString($tenant->slug, $output->fetch());

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, 'delivery:fetch-inbound')
                && ($context['company'] ?? null) === $tenant->slug
        );
    }

    private function makeReadableTenant(): Company
    {
        return Company::create([
            'name' => 'Inbound Fail',
            'slug' => 'inbound-fail-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }
}
