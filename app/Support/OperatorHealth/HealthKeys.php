<?php

namespace App\Support\OperatorHealth;

final class HealthKeys
{
    public const QUEUE_HEARTBEAT = 'ops.health.queue_worker.last_heartbeat_at';

    public const BACKUP_MONITOR_RESULT = 'ops.health.backup.monitor.last_result';

    public static function scheduledTask(string $task): string
    {
        return "ops.health.schedule.{$task}";
    }

    public static function whmcsFetch(int $companyId): string
    {
        return "ops.health.whmcs_fetch.{$companyId}";
    }

    public static function myDataReconcile(int $companyId): string
    {
        return "ops.health.mydata_reconcile.{$companyId}";
    }
}
