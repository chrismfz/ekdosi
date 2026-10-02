<?php

namespace App\Support\Settings;

use Cron\CronExpression;

/**
 * Resolves a scheduled task's TIMING (cron expression or HH:MM) from the
 * «Σύστημα → Χρονοπρογραμματιστής» UI: the `system_settings` override wins, the
 * config/env value is the DEFAULT (empty store = exactly today's behaviour).
 *
 * Belt-and-braces: a stored value that is NOT a valid cron / HH:MM is IGNORED
 * (falls back to the default), so a bad row can never break `schedule:run` for
 * EVERY task — the UI validates on save, this is the guard behind it. Kept as a
 * tiny testable class (not an inline closure in routes/console.php) so the
 * override + fallback are unit-covered.
 */
class ScheduleTiming
{
    /**
     * A task's enable switch: the UI override wins, config/env is the default.
     * THE one reader — routes/console.php (what actually runs), ops:health and the
     * inbox auto-issue badge must never disagree on it.
     */
    public static function enabled(string $key): bool
    {
        return app(SystemSettings::class)->bool("schedule.{$key}", (bool) config("ekdosi.schedule.{$key}"));
    }

    public static function cron(string $key, string $default): string
    {
        $stored = (string) app(SystemSettings::class)->string("schedule.{$key}", $default);

        return CronExpression::isValidExpression($stored) ? $stored : $default;
    }

    public static function time(string $key, string $default): string
    {
        $stored = (string) app(SystemSettings::class)->string("schedule.{$key}", $default);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $stored) === 1 ? $stored : $default;
    }
}
