<?php

namespace Tests\Feature\Console;

use App\Filament\Pages\ScheduleSettings;
use Tests\TestCase;

/**
 * Slice B: suppliers:sync / customers:sync are wired into the scheduler as
 * per-tenant, page-controllable tasks — READ-from-AADE, write-only-to-partners,
 * default OFF (they write master data). The generic SchedulerCoverageTest already
 * enforces "gated + on the page"; this pins the B-specific contract.
 */
class SuppliersCustomersSyncScheduleTest extends TestCase
{
    public function test_both_syncs_are_scheduled_gated_and_on_the_page(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        $expected = [
            'suppliers:sync' => ['suppliers-sync-all', 'suppliers_sync_enabled', 'suppliers_sync_cron'],
            'customers:sync' => ['customers-sync-all', 'customers_sync_enabled', 'customers_sync_cron'],
        ];

        foreach ($expected as $command => [$name, $flag, $cron]) {
            // Scheduled, per-tenant (via the sweep), named, and gated by its own flag.
            $this->assertStringContainsString("'{$command}'", $console, "{$command} πρέπει να είναι scheduled");
            $this->assertStringContainsString("->name('{$name}')", $console);
            $this->assertStringContainsString("\$scheduleEnabled('{$flag}')", $console);

            // Config: flag defaults OFF (writes master data → opt-in) and cron present.
            $this->assertFalse((bool) config("ekdosi.schedule.{$flag}"), "{$flag} πρέπει να είναι default OFF");
            $this->assertNotSame('', (string) config("ekdosi.schedule.{$cron}"), "{$cron} χρειάζεται default");

            // Controllable from the «Χρονοπρογραμματιστής» page.
            $this->assertContains($flag, ScheduleSettings::taskKeys(), "{$flag} πρέπει να είναι στη σελίδα");
            $this->assertContains($cron, ScheduleSettings::timingKeys(), "{$cron} πρέπει να είναι στη σελίδα");
        }
    }

    public function test_the_syncs_iterate_mydata_readable_tenants(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        // Both go through the per-tenant sweep over myDataReadable() (like reconcile),
        // NOT a bare Schedule::command (which would fail — the commands require --tenant).
        foreach (['suppliers:sync', 'customers:sync'] as $command) {
            $this->assertMatchesRegularExpression(
                '~\$sweepTenants\(\s*Company::myDataReadable\(\),\s*\''.preg_quote($command, '~').'\'~s',
                $console,
                "{$command} πρέπει να τρέχει per myDATA-readable tenant μέσω \$sweepTenants",
            );
        }
    }
}
