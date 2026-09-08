<?php

namespace App\Support\Backup;

use App\Models\User;
use App\Support\Settings\SystemSettings;

/**
 * The ONE resolver for "who gets backup-problem emails". Shared by the
 * per-company pipeline (company:run-scheduled-backups → ScheduledBackupFailed)
 * AND the global spatie/laravel-backup notifications (OpsBackupNotifiable) so
 * the two paths can never drift to different ops inboxes.
 *
 * Chain: the «Ρυθμίσεις συστήματος» DB override → EKDOSI_BACKUP_ALERT_EMAIL
 * (comma-separated) → every super_admin user with an email. De-duplicated.
 */
class BackupAlertRecipients
{
    /** @return array<int, string> */
    public static function resolve(): array
    {
        $email = app(SystemSettings::class)->string(
            'system.backup_alert_email',
            (string) config('ekdosi.backup.alert_email'),
        );

        $configured = array_filter(array_map('trim', explode(',', (string) $email)));
        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }
}
