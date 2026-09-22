<?php

namespace Tests\Feature\Delivery;

use App\Console\Commands\DeliveryFetchInbound;
use App\Models\Company;
use App\Services\Delivery\InboundDeliveryFetcher;
use App\Services\Delivery\InboundFetchResult;
use App\Support\OperatorHealth\HealthRecorder;
use App\Support\OperatorHealth\OperatorHealthReport;
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

    public function test_persistent_fetch_failure_escalates_to_error_and_flags_ops_health(): void
    {
        $tenant = $this->makeReadableTenant();

        Log::spy();

        // The Nth consecutive failure (bad/expired creds, not a blip) must escalate
        // from warning to error AND make ops:health flag the tenant — the silent
        // multi-day staging stop this hardening closes.
        // Run the SCHEDULED sweep (no --tenant): only that advances the health streak.
        // RefreshDatabase leaves this as the single myData-readable tenant. Run ONE
        // past the threshold to prove the error escalation fires only at the crossing.
        $threshold = HealthRecorder::DELIVERY_INBOUND_PERSISTENT_FAILURES;
        for ($i = 0; $i < $threshold + 1; $i++) {
            $command = $this->throwingCommand();
            $exit = $command->run(new ArrayInput([]), new BufferedOutput);
            $this->assertSame(0, $exit, 'a read-only poll never fails the run, even when persistent');
        }

        // Error is logged EXACTLY ONCE (at the crossing), not on every later run —
        // ops:health carries the ongoing signal, so a log-alerter doesn't re-page.
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($message, $context = []) => str_contains((string) $message, 'delivery:fetch-inbound')
                && str_contains((string) $message, 'in a row')
                && ($context['company'] ?? null) === $tenant->slug
                && ($context['consecutive_failures'] ?? 0) === $threshold
        );

        $report = app(OperatorHealthReport::class)->build();
        $row = collect($report['delivery_inbound'])->firstWhere('tenant', $tenant->slug);
        $this->assertNotNull($row, 'the tenant appears in the delivery-inbound health section');
        $this->assertTrue($row['persistent'], 'N consecutive failures mark the tenant persistent');
        $this->assertSame($threshold + 1, $row['consecutive_failures']);

        $severity = $report['severity'];
        $this->assertNotSame('critical', $severity['level'], 'read-only staging is a warning, never critical');
        $this->assertStringContainsString($tenant->slug, implode(' ', $severity['warnings']));
    }

    public function test_config_guard_is_quiet_and_does_not_record_a_failure(): void
    {
        $tenant = $this->makeReadableTenant();

        Log::spy();

        // A RuntimeException from the fetcher = the config guard (mode off / missing
        // creds): an expected state, not an error. It must NOT log error/warning and
        // NOT record a failure (such a tenant drops out of myDataReadable on its own).
        $guard = new class extends DeliveryFetchInbound
        {
            protected function fetcherFor(Company $tenant): InboundDeliveryFetcher
            {
                return new class($tenant) extends InboundDeliveryFetcher
                {
                    public function fetch(?Carbon $from = null, ?Carbon $to = null, bool $dryRun = false): InboundFetchResult
                    {
                        throw new \RuntimeException('Delivery inbound is off for this tenant.');
                    }
                };
            }
        };
        $guard->setLaravel($this->app);
        $exit = $guard->run(new ArrayInput([]), new BufferedOutput);
        $this->assertSame(0, $exit);

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');

        $row = collect(app(OperatorHealthReport::class)->build()['delivery_inbound'])->firstWhere('tenant', $tenant->slug);
        $this->assertNotNull($row);
        $this->assertFalse($row['persistent'], 'a config-guard skip never marks the tenant persistent');
        $this->assertSame(0, $row['consecutive_failures']);
    }

    /** A DeliveryFetchInbound whose fetcher always raises a transient AADE error. */
    private function throwingCommand(): DeliveryFetchInbound
    {
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

        return $command;
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
