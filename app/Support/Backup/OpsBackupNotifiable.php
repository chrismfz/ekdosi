<?php

namespace App\Support\Backup;

use Spatie\Backup\Notifications\Notifiable;

/**
 * AUDIT OPS-2: spatie's stock Notifiable mails the static
 * config('backup.notifications.mail.to') — which shipped as the package
 * placeholder 'your@example.com', so whole-DB backup failures notified
 * nobody. Route to the real ops recipients instead, through the SAME chain
 * the per-company backup alerts use (BackupAlertRecipients: DB setting →
 * EKDOSI_BACKUP_ALERT_EMAIL → super_admins).
 *
 * The config 'to' stays as a valid-shaped placeholder because spatie
 * validates it eagerly when building its Config object; it is only ever
 * used as a last-resort fallback if the resolver comes back empty
 * (no setting, no env, no super_admin with an email).
 */
class OpsBackupNotifiable extends Notifiable
{
    /** @return string|array<int, string> */
    public function routeNotificationForMail(): string|array
    {
        $recipients = BackupAlertRecipients::resolve();

        return $recipients !== [] ? $recipients : parent::routeNotificationForMail();
    }
}
