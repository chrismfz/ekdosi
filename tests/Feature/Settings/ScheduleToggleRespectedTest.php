<?php

namespace Tests\Feature\Settings;

use App\Support\Settings\SystemSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves routes/console.php's `->when()` filter actually reads the system_settings
 * override (env stays the default) — the run-time half of the «Σύστημα» toggle UI.
 */
class ScheduleToggleRespectedTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $summary): Event
    {
        app(ConsoleKernel::class)->bootstrap();
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if ($event->getSummaryForDisplay() === $summary) {
                return $event;
            }
        }

        $this->fail("Scheduled event [{$summary}] not found");
    }

    #[Test]
    public function a_db_override_disables_an_env_enabled_task(): void
    {
        // mydata-reconcile defaults ON in config → filter passes with no override.
        $this->assertTrue($this->event('mydata-reconcile-all')->filtersPass($this->app));

        app(SystemSettings::class)->setBool('schedule.mydata_reconcile_enabled', false, null);

        $this->assertFalse($this->event('mydata-reconcile-all')->filtersPass($this->app));
    }

    #[Test]
    public function a_db_override_enables_an_env_disabled_task(): void
    {
        // overdue-notifications defaults OFF in config → filter blocks it.
        $this->assertFalse($this->event('invoices-notify-overdue')->filtersPass($this->app));

        app(SystemSettings::class)->setBool('schedule.overdue_notifications_enabled', true, null);

        $this->assertTrue($this->event('invoices-notify-overdue')->filtersPass($this->app));
    }
}
