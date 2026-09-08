<?php

namespace App\Support\ErrorAlerts;

use App\Support\Backup\BackupAlertRecipients;
use App\Support\Settings\SystemSettings;

/**
 * OPS-3: who gets emailed when the app throws an unhandled exception.
 *
 * Uses the dedicated error-alert email when set (the «Ρυθμίσεις συστήματος» UI
 * override wins over EKDOSI_ERROR_ALERT_EMAIL); otherwise falls back to the SAME
 * chain the backup alerts already use (backup-alert email → super_admin users).
 * Reusing that resolver keeps every ops alert pointed at one inbox instead of
 * drifting apart.
 */
class ExceptionAlertRecipients
{
    /** @return array<int, string> */
    public static function resolve(): array
    {
        $email = (string) app(SystemSettings::class)->string('system.error_alert_email', (string) config('ekdosi.error_alerts.email'));
        $configured = array_values(array_unique(array_filter(array_map('trim', explode(',', $email)))));

        if ($configured !== []) {
            return $configured;
        }

        return BackupAlertRecipients::resolve();
    }
}
