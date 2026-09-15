<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ScheduleSettings;
use Tests\TestCase;

/**
 * Guards that every scheduled feature is CONTROLLABLE from the «Ρυθμίσεις
 * χρονοπρογραμματιστή» page — so a new Schedule::… can't quietly go «αδέσποτο»
 * (live but invisible/uncontrollable from the UI), which is exactly how
 * delivery_fetch_inbound / intent_expiry / ai_reminders slipped through before.
 */
class SchedulerCoverageTest extends TestCase
{
    /**
     * Tasks deliberately NOT surfaced in the page: pure housekeeping/infra with no
     * operator-facing meaning. Anything else MUST appear in ScheduleSettings::TASKS.
     * Surfacing one of these later → drop it here (test_the_hidden_list_stays_honest).
     */
    private const INTENTIONALLY_HIDDEN = [
        'prune_auth_events_enabled', // periodic auth-event pruning — always-on housekeeping
        'self_update_enabled',       // out-of-band in-app update applier (own «Ενημερώσεις» screen)
    ];

    public function test_every_schedule_flag_is_in_the_page_or_documented_hidden(): void
    {
        $enabled = array_filter(
            array_keys(config('ekdosi.schedule')),
            fn (string $k): bool => str_ends_with($k, '_enabled'),
        );
        $exposed = ScheduleSettings::taskKeys();

        foreach ($enabled as $key) {
            $this->assertTrue(
                in_array($key, $exposed, true) || in_array($key, self::INTENTIONALLY_HIDDEN, true),
                "Το schedule flag '{$key}' πρέπει να εμφανίζεται στη σελίδα «Χρονοπρογραμματιστής» "
                .'(ScheduleSettings::TASKS) ή να προστεθεί ρητά στο INTENTIONALLY_HIDDEN αυτού του test.',
            );
        }
    }

    public function test_the_hidden_list_stays_honest(): void
    {
        foreach (self::INTENTIONALLY_HIDDEN as $key) {
            $this->assertNotContains($key, ScheduleSettings::taskKeys(),
                "Το '{$key}' μπήκε στη σελίδα — αφαίρεσέ το από το INTENTIONALLY_HIDDEN.");
            $this->assertNotNull(config("ekdosi.schedule.{$key}"),
                "Το hidden key '{$key}' δεν υπάρχει πια στο config — καθάρισε το test.");
        }
    }

    public function test_every_exposed_task_and_timing_has_a_config_default(): void
    {
        foreach (ScheduleSettings::taskKeys() as $key) {
            $this->assertNotNull(config("ekdosi.schedule.{$key}"),
                "Το task '{$key}' της σελίδας δεν έχει default στο config/ekdosi.php.");
        }
        foreach (ScheduleSettings::timingKeys() as $key) {
            $this->assertNotSame('', (string) config("ekdosi.schedule.{$key}"),
                "Το timing '{$key}' της σελίδας δεν έχει default στο config (κενό placeholder).");
        }
    }

    public function test_every_routes_console_gate_key_exists_in_config(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));
        preg_match_all('~\$scheduleEnabled\(\s*\'([^\']+)\'\s*\)~', $console, $m);
        $gateKeys = array_values(array_unique($m[1]));

        $this->assertNotEmpty($gateKeys, 'Δεν βρέθηκε κανένα gate key — μήπως άλλαξε το pattern του $scheduleEnabled;');

        foreach ($gateKeys as $key) {
            $this->assertNotNull(config("ekdosi.schedule.{$key}"),
                "Το routes/console.php χρησιμοποιεί gate '{$key}' που δεν υπάρχει στο config (typo;).");
        }
    }
}
