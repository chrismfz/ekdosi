<?php

namespace App\Support\Backup;

use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Throwable;

/**
 * Is the nightly whole-DB backup missing — i.e. would `backup:monitor` call it stale?
 *
 * The scheduler SKIPS every task while the app is in maintenance mode, so a deploy
 * that straddles 02:00 silently drops that night's `backup:run` (seen 2026-09-26).
 * An hourly catch-up asks this and runs the backup late instead of leaving a day's
 * gap for the 08:00 monitor to report. Same threshold as the monitor (max age in
 * days) + 1 hour slack, so a normal night never triggers it — even a long 02:00 run.
 */
class BackupOverdue
{
    public static function check(): bool
    {
        $maxDays = (int) (config('backup.monitor_backups.0.health_checks.'.MaximumAgeInDays::class) ?? 1);
        $threshold = now()->subDays(max(1, $maxDays))->subHour();

        try {
            foreach (BackupDestinationFactory::createFromArray(app(Config::class)) as $destination) {
                $newest = $destination->newestBackup();
                if ($newest === null || $newest->date()->lt($threshold)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // An unreachable destination is backup:monitor's to report, not a reason to loop backups.
            report($e);
        }

        return false;
    }
}
