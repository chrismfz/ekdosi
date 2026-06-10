<?php

namespace Tests\Feature\Backup;

use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Notifications\ScheduledBackupFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backup\Support\ThrowingBackupDestination;
use Tests\TestCase;

/**
 * Failure alerting for the unattended path: when `company:run-scheduled-backups`
 * produces a failed/partial run, ops gets emailed (and it's always logged).
 */
class BackupFailureAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Register a destination that always fails, so a run goes partial.
        config(['ekdosi.backup.destinations.boom' => ThrowingBackupDestination::class]);
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'Alert tenant', 'slug' => 'al-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
    }

    private function settings(Company $c, array $destinations): void
    {
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily',
            'bucket' => 'settings_setup', 'secrets_mode' => 'raw', 'retention_keep' => 2,
            'destinations' => $destinations,
        ]);
    }

    #[Test]
    public function a_partial_scheduled_run_emails_the_configured_ops_address(): void
    {
        Notification::fake();
        config(['ekdosi.backup.alert_email' => 'ops@example.gr']);

        $this->settings($this->company(), [['driver' => 'boom']]);

        $this->artisan('company:run-scheduled-backups', ['--force' => true])->assertSuccessful();

        Notification::assertSentOnDemand(
            ScheduledBackupFailed::class,
            fn ($notification, array $channels, $notifiable) => in_array('ops@example.gr', (array) ($notifiable->routes['mail'] ?? []), true),
        );
    }

    #[Test]
    public function a_clean_run_alerts_nobody(): void
    {
        Notification::fake();
        config(['ekdosi.backup.alert_email' => 'ops@example.gr']);

        // Local-only (auto-added) → succeeds → no alert.
        $this->settings($this->company(), []);

        $this->artisan('company:run-scheduled-backups', ['--force' => true])->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function alerting_can_be_switched_off(): void
    {
        Notification::fake();
        config(['ekdosi.backup.alert_email' => 'ops@example.gr', 'ekdosi.backup.alert_on_failure' => false]);

        $this->settings($this->company(), [['driver' => 'boom']]);

        $this->artisan('company:run-scheduled-backups', ['--force' => true])->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_db_override_can_switch_alerting_off_over_the_config_default(): void
    {
        Notification::fake();
        // Config DEFAULT says alert (true); the «Ρυθμίσεις συστήματος» override wins.
        config(['ekdosi.backup.alert_email' => 'ops@example.gr', 'ekdosi.backup.alert_on_failure' => true]);
        app(\App\Support\Settings\SystemSettings::class)->setBool('system.backup_alert_on_failure', false, null);

        $this->settings($this->company(), [['driver' => 'boom']]);

        $this->artisan('company:run-scheduled-backups', ['--force' => true])->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_db_override_email_takes_precedence_over_config(): void
    {
        Notification::fake();
        config(['ekdosi.backup.alert_email' => 'config@example.gr']);
        app(\App\Support\Settings\SystemSettings::class)->set('system.backup_alert_email', 'override@example.gr', 'string', null);

        $this->settings($this->company(), [['driver' => 'boom']]);

        $this->artisan('company:run-scheduled-backups', ['--force' => true])->assertSuccessful();

        Notification::assertSentOnDemand(
            ScheduledBackupFailed::class,
            fn ($notification, array $channels, $notifiable) => in_array('override@example.gr', (array) ($notifiable->routes['mail'] ?? []), true),
        );
    }

    #[Test]
    public function no_recipients_configured_does_not_crash(): void
    {
        Notification::fake();
        config(['ekdosi.backup.alert_email' => null]); // and no super_admin users exist

        $this->settings($this->company(), [['driver' => 'boom']]);

        $this->artisan('company:run-scheduled-backups', ['--force' => true])->assertSuccessful();

        Notification::assertNothingSent();
    }
}
