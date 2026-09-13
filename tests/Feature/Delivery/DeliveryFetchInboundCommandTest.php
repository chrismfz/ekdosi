<?php

namespace Tests\Feature\Delivery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 4a — the command's tenant-resolution glue, exercised WITHOUT touching
 * AADE (no myDATA-readable tenant / an unknown tenant → the command short-circuits
 * before any RequestDocs call). The network path itself is covered by
 * InboundDeliveryFetcherTest.
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
}
