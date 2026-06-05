# Operator health checks

Run the operator health report from the application root:

```bash
php artisan ops:health
```

For monitoring systems, use JSON output:

```bash
php artisan ops:health --json
```

The report is read-only. It collects the checks operators need before trusting the
invoice, WHMCS, myDATA, mail, queue, and backup automation.

## What it checks

| Area | Signals |
| --- | --- |
| Queue | Last handled queue-worker heartbeat and `failed_jobs` count. |
| Scheduler | Last run timestamp/status for WHMCS fetch, myDATA reconcile, VAT picture refresh, mail-log sweep, backup run, backup cleanup, and backup monitor. |
| Backups | Latest local backup path, age, size, and last `backup:monitor` result. |
| Mail | Failed invoice mail counts for 24h/7d, stuck `queued`/`sending` rows, and latest invoice mail failure time. |
| WHMCS | Per-tenant last successful/failed `whmcs:fetch-pending` run, last exit code, and pending inbox count. |
| myDATA | Per-tenant reconciliation discrepancies, last successful reconcile, last successful AADE reconcile call, and latest local myDATA MARK timestamp. |
| Disk | Directory size and filesystem free/total bytes for `storage/`, `storage/logs/`, Livewire temp uploads, and local backups. |

## Health data sources

* Queue heartbeat is a tiny scheduled queued job. The heartbeat only updates
  after a running queue worker handles `App\Jobs\RecordQueueHeartbeat`; a stale
  heartbeat means the scheduler may be dispatching but the worker is not keeping
  up.
* Scheduler timestamps are written from `routes/console.php` hooks around the
  Laravel scheduled tasks.
* WHMCS and myDATA tenant status is written by the business commands themselves
  (`whmcs:fetch-pending` and `mydata:reconcile-sales`) so manual runs update the
  report too.
* Mail counts come from `invoice_mail_log`; queue failures come from
  `failed_jobs`.
* Backup age/size is discovered from the local backup disk folder named after
  `config('backup.backup.name')`. The monitor status is populated when the
  scheduled `backup:monitor` command runs through Laravel's scheduler.

## Scheduler configuration

The heartbeat is enabled by default:

```dotenv
EKDOSI_SCHEDULE_QUEUE_HEARTBEAT=true
```

Backup scheduling is opt-in because some deployments run Spatie backup commands
from system cron or systemd instead of Laravel's scheduler:

```dotenv
EKDOSI_SCHEDULE_BACKUP_RUN=true
EKDOSI_BACKUP_RUN_CRON="0 2 * * *"
EKDOSI_SCHEDULE_BACKUP_CLEANUP=true
EKDOSI_BACKUP_CLEANUP_CRON="30 2 * * *"
EKDOSI_SCHEDULE_BACKUP_MONITOR=true
EKDOSI_BACKUP_MONITOR_CRON="0 8 * * *"
```

If backups are run outside Laravel, `ops:health` still reports the latest local
backup age/size, but the scheduler timestamp and monitor result will remain
`missing` unless those commands run through `schedule:run`.

## Suggested operator triage

1. If queue heartbeat is missing/stale, restart or inspect the queue worker first.
2. If scheduler timestamps are stale, inspect the OS cron entry that runs
   `php artisan schedule:run` every minute.
3. If `failed_jobs` is non-zero, inspect and retry/forget failed jobs after
   fixing the underlying issue.
4. If mail failures rise, run `php artisan mail-log:sweep-orphans --dry-run` and
   validate SMTP settings before enabling automatic re-send.
5. If WHMCS fetch or myDATA reconcile shows failures/discrepancies, run the
   tenant command manually with the tenant slug shown in the report.
6. If backup monitor is failed/missing or the latest backup is old/small, run
   `php artisan backup:run` and then `php artisan backup:monitor` from the app
   user.
