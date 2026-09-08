<?php

namespace Tests\Feature\Backup;

use App\Models\CompanyBackupSetting;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure cadence logic for the scheduler — CompanyBackupSetting::isDue(lastRunAt,
 * now). No DB: isDue is a function of the policy + the last attempt time.
 */
class CompanyBackupScheduleTest extends TestCase
{
    private function setting(string $frequency, string $time = '02:00'): CompanyBackupSetting
    {
        return new CompanyBackupSetting(['frequency' => $frequency, 'run_at_time' => $time]);
    }

    #[Test]
    public function not_due_before_the_configured_time(): void
    {
        $now = CarbonImmutable::parse('2026-06-09 01:30'); // before 02:00
        $this->assertFalse($this->setting('daily')->isDue(null, $now));
    }

    #[Test]
    public function daily_is_due_once_per_day_after_the_time(): void
    {
        $now = CarbonImmutable::parse('2026-06-09 02:05');

        $this->assertTrue($this->setting('daily')->isDue(null, $now), 'never run → due');
        $this->assertTrue($this->setting('daily')->isDue(CarbonImmutable::parse('2026-06-08 02:05'), $now), 'ran yesterday → due');
        $this->assertFalse($this->setting('daily')->isDue(CarbonImmutable::parse('2026-06-09 02:01'), $now), 'already ran today → not due');
    }

    #[Test]
    public function a_failed_attempt_today_still_blocks_re_running_today(): void
    {
        // The infinite-retry guard: isDue takes the last ATTEMPT regardless of
        // status, so a failed run earlier today must NOT re-fire every hour.
        $now = CarbonImmutable::parse('2026-06-09 14:00');
        $failedEarlierToday = CarbonImmutable::parse('2026-06-09 02:00');

        $this->assertFalse($this->setting('daily')->isDue($failedEarlierToday, $now));
    }

    #[Test]
    public function weekly_and_monthly_fire_once_per_period(): void
    {
        $now = CarbonImmutable::parse('2026-06-10 03:00'); // a Wednesday

        $this->assertTrue($this->setting('weekly')->isDue(CarbonImmutable::parse('2026-06-01 03:00'), $now), 'last week → due');
        $this->assertFalse($this->setting('weekly')->isDue($now->startOfWeek()->addHour(), $now), 'this week → not due');

        $this->assertTrue($this->setting('monthly')->isDue(CarbonImmutable::parse('2026-05-30 03:00'), $now), 'last month → due');
        $this->assertFalse($this->setting('monthly')->isDue(CarbonImmutable::parse('2026-06-02 03:00'), $now), 'this month → not due');
    }

    #[Test]
    public function off_frequency_is_never_due(): void
    {
        $now = CarbonImmutable::parse('2026-06-09 23:59');
        $this->assertFalse($this->setting('off')->isDue(null, $now));
    }
}
