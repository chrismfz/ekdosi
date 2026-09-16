<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Regression: the mydata:refresh-expenses scheduled task passed its VALUE_NONE
 * flag as ['--auto-only' => true]. Laravel's Event::compileParameters serializes
 * a string key to "{$key}={$value}", so that became --auto-only='1' — which
 * Symfony then rejects ("The '--auto-only' option does not accept a value.") on
 * EVERY scheduled run, spamming logs/mail (default every 6h). The fix passes the flag as the
 * value-less ['--auto-only'] (numeric key → bare token). This pins the COMPILED
 * command string so a text-only diff can't silently reintroduce the '=1' form.
 */
class RefreshExpensesScheduleFlagTest extends TestCase
{
    public function test_auto_only_is_a_valueless_flag_in_the_compiled_command(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = collect($schedule->events())->first(
            static fn ($e): bool => str_contains((string) ($e->command ?? ''), 'mydata:refresh-expenses')
        );

        $this->assertNotNull($event, 'Το mydata:refresh-expenses πρέπει να είναι scheduled.');

        $command = (string) $event->command;
        $this->assertStringContainsString('--auto-only', $command,
            'Ο αυτόματος sweep πρέπει να περνά το --auto-only.');
        // The bug produced --auto-only='1'; a VALUE_NONE flag must carry NO '='.
        $this->assertStringNotContainsString('--auto-only=', $command,
            "Το --auto-only πρέπει να περνά ως value-less flag, όχι ως --auto-only='1'.");
    }
}
