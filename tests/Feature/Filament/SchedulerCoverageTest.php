<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ScheduleSettings;
use Tests\TestCase;

/**
 * Guards that every scheduled feature is CONTROLLABLE from the «Ρυθμίσεις
 * χρονοπρογραμματιστή» page — so a new Schedule::… can't quietly go «αδέσποτο»
 * (live but invisible/uncontrollable from the UI), which is exactly how
 * delivery_fetch_inbound / intent_expiry / ai_reminders slipped through before.
 *
 * Three complementary guards:
 *   1. every `schedule.*_enabled` config flag is on the page (or documented hidden);
 *   2. every `$scheduleEnabled('KEY')` gate used in routes/console.php is on the page
 *      (or documented hidden) — regardless of the key's naming;
 *   3. every `Schedule::…` entry is GATED (→ controllable) or on an explicit
 *      always-on-infra allowlist.
 */
class SchedulerCoverageTest extends TestCase
{
    /**
     * Gate keys deliberately NOT surfaced in the page: pure housekeeping/infra with
     * no operator-facing meaning. Anything else MUST appear in ScheduleSettings::TASKS.
     */
    private const INTENTIONALLY_HIDDEN = [
        'prune_auth_events_enabled', // periodic auth-event pruning — always-on housekeeping
        'self_update_enabled',       // out-of-band in-app update applier (own «Ενημερώσεις» screen)
    ];

    /**
     * `Schedule::…` entries that legitimately run ALWAYS (no per-deploy toggle) — pure
     * infra. Identified by their ->name('…'), else by the `Schedule::command('…')` string.
     */
    private const UNGATED_INFRA = [
        'scheduler-heartbeat',            // records the cron tick for health — must always run
        'queue:prune-failed --hours=336', // Laravel failed-jobs table pruning — housekeeping
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

    public function test_every_routes_console_gate_key_is_on_the_page_or_hidden(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));
        preg_match_all('~\$scheduleEnabled\(\s*\'([^\']+)\'\s*\)~', $console, $m);
        $gateKeys = array_values(array_unique($m[1]));

        $this->assertNotEmpty($gateKeys, 'Δεν βρέθηκε κανένα gate key — μήπως άλλαξε το pattern του $scheduleEnabled;');

        $exposed = ScheduleSettings::taskKeys();
        foreach ($gateKeys as $key) {
            // A gate used by the scheduler must exist in config AND be controllable from
            // the page (or be documented infra) — regardless of the key's naming.
            $this->assertNotNull(config("ekdosi.schedule.{$key}"),
                "Το routes/console.php χρησιμοποιεί gate '{$key}' που δεν υπάρχει στο config (typo;).");
            $this->assertTrue(
                in_array($key, $exposed, true) || in_array($key, self::INTENTIONALLY_HIDDEN, true),
                "Το scheduler gate '{$key}' δεν εμφανίζεται στη σελίδα «Χρονοπρογραμματιστής» "
                .'ούτε είναι στο INTENTIONALLY_HIDDEN — θα «ζούσε» εκτός UI.',
            );
        }
    }

    public function test_every_scheduled_task_is_gated_or_documented_infra(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));
        $chunks = preg_split('~(?=Schedule::(?:command|call|job)\()~', $console);

        $seen = 0;
        foreach ($chunks as $chunk) {
            if (! preg_match('~^Schedule::(command|call|job)\(~', $chunk)) {
                continue;
            }
            $seen++;
            if (str_contains($chunk, '$scheduleEnabled(')) {
                continue; // gated → controllable from the page (asserted above)
            }
            // Ungated: identify by ->name('X'), else by the Schedule::command('X') string.
            if (preg_match('~->name\(\s*\'([^\']+)\'~', $chunk, $nm)) {
                $id = $nm[1];
            } elseif (preg_match('~^Schedule::command\(\s*\'([^\']+)\'~', $chunk, $cm)) {
                $id = $cm[1];
            } else {
                $id = '(unidentified Schedule entry)';
            }

            $this->assertContains($id, self::UNGATED_INFRA,
                "Το scheduled task «{$id}» δεν έχει \$scheduleEnabled gate, άρα δεν ελέγχεται από τη "
                .'σελίδα «Χρονοπρογραμματιστής». Πρόσθεσε gate + entry στη σελίδα, ή, αν είναι σκόπιμα '
                .'always-on infra, βάλ᾽ το ρητά στο UNGATED_INFRA αυτού του test.');
        }

        $this->assertGreaterThan(20, $seen, 'Πολύ λίγα Schedule:: εντοπίστηκαν — μήπως άλλαξε το parsing;');
    }
}
