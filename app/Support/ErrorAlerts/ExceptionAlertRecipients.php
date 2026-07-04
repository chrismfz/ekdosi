<?php

namespace App\Support\ErrorAlerts;

use App\Support\Backup\BackupAlertRecipients;

/**
 * OPS-3: who gets emailed when the app throws an unhandled exception.
 *
 * Uses the dedicated EKDOSI_ERROR_ALERT_EMAIL when set; otherwise falls back to
 * the SAME chain the backup alerts already use (EKDOSI_BACKUP_ALERT_EMAIL →
 * super_admin users). Reusing that resolver keeps every ops alert pointed at
 * one inbox instead of drifting apart.
 */
class ExceptionAlertRecipients
{
    /** @return array<int, string> */
    public static function resolve(): array
    {
        $email = (string) config('ekdosi.error_alerts.email');
        $configured = array_values(array_unique(array_filter(array_map('trim', explode(',', $email)))));

        if ($configured !== []) {
            return $configured;
        }

        return BackupAlertRecipients::resolve();
    }
}
