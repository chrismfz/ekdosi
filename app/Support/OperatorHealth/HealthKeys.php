<?php

namespace App\Support\OperatorHealth;

final class HealthKeys
{
    public const QUEUE_HEARTBEAT = 'ops.health.queue_worker.last_heartbeat_at';

    /**
     * OPS-001: the SCHEDULER tick — written SYNCHRONOUSLY inside `schedule:run`
     * every minute (no queue worker involved). Distinct from QUEUE_HEARTBEAT,
     * which only turns green when BOTH the cron fires AND a worker processes the
     * dispatched job. Comparing the two disambiguates «cron down» (this key stale)
     * from «worker down» (this fresh, queue heartbeat stale).
     */
    public const SCHEDULER_HEARTBEAT = 'ops.health.scheduler.last_tick_at';

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

    public static function deliveryInbound(int $companyId): string
    {
        return "ops.health.delivery_inbound.{$companyId}";
    }
}
