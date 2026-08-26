<?php

namespace Tests\Feature\Settings;

use App\Support\Settings\ScheduleTiming;
use App\Support\Settings\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «Χρονισμός» half of the schedule UI: routes/console.php resolves each task's
 * cron/time through ScheduleTiming, so a stored override wins over the config
 * default — and an INVALID stored value is ignored (falls back), so a bad row can
 * never break schedule:run for every task.
 */
class ScheduleTimingResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cron_returns_the_default_with_no_override(): void
    {
        $this->assertSame('0 2 * * *', ScheduleTiming::cron('backup_run_cron', '0 2 * * *'));
    }

    #[Test]
    public function a_valid_cron_override_wins(): void
    {
        app(SystemSettings::class)->set('schedule.backup_run_cron', '15 4 * * *', 'string');

        $this->assertSame('15 4 * * *', ScheduleTiming::cron('backup_run_cron', '0 2 * * *'));
    }

    #[Test]
    public function an_invalid_cron_override_falls_back_to_the_default(): void
    {
        app(SystemSettings::class)->set('schedule.backup_run_cron', 'δεν-είναι-cron', 'string');

        $this->assertSame('0 2 * * *', ScheduleTiming::cron('backup_run_cron', '0 2 * * *'));
    }

    #[Test]
    public function a_valid_time_override_wins_and_an_invalid_one_falls_back(): void
    {
        $this->assertSame('06:00', ScheduleTiming::time('mydata_reconcile_time', '06:00'));

        app(SystemSettings::class)->set('schedule.mydata_reconcile_time', '07:15', 'string');
        $this->assertSame('07:15', ScheduleTiming::time('mydata_reconcile_time', '06:00'));

        app(SystemSettings::class)->set('schedule.mydata_reconcile_time', '99:99', 'string');
        $this->assertSame('06:00', ScheduleTiming::time('mydata_reconcile_time', '06:00'));
    }
}
