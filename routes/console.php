<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Models\AuthEvent;
use App\Models\Company;
use App\Models\UpdateRun;
use App\Support\OperatorHealth\HealthRecorder;
use App\Support\OperatorHealth\TenantScheduleSweep;
use App\Support\Settings\ScheduleTiming;
use App\Support\Settings\SystemSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$trackSchedule = function ($event, string $task) {
    return $event
        ->before(fn () => app(HealthRecorder::class)->recordScheduledRun($task, 'running'))
        ->onSuccess(fn () => app(HealthRecorder::class)->recordScheduledRun($task, 'ok', 0))
        ->onFailure(fn () => app(HealthRecorder::class)->recordScheduledRun($task, 'failed', 1));
};

/*
 | Per-task enable switch (the «Σύστημα» settings UI). The DB override wins; the
 | config/env flag is the DEFAULT (empty system_settings = exactly today's
 | behaviour). Evaluated as a RUN-TIME `->when()` filter, never at file load —
 | so this stays DB-query-free when the file is merely parsed (migrate etc.),
 | and a disabled task is filtered before its before/onSuccess hooks fire (no
 | run-history pollution). The UI can therefore also turn ON a task that env
 | left off, which registration-gating couldn't.
 */
$scheduleEnabled = fn (string $key): bool => app(SystemSettings::class)
    ->bool("schedule.{$key}", (bool) config("ekdosi.schedule.{$key}"));

/*
 | Per-task TIMING override (same «Σύστημα» settings UI, «Χρονισμός»). The DB
 | override wins; the config/env value is the DEFAULT. Defense-in-depth: a stored
 | value that is NOT a valid cron / HH:MM is IGNORED (fall back to the config
 | default) so one bad row can never break schedule:run for EVERY task — the UI
 | validates on save, this is the belt to that braces. Run-time like the enable
 | switch (never a DB query at file-parse time).
 */
$scheduleCron = fn (string $key, string $default): string => ScheduleTiming::cron($key, $default);
$scheduleTime = fn (string $key, string $default): string => ScheduleTiming::time($key, $default);

// OPS-13: per-tenant sweeps with per-tenant isolation (one tenant's uncaught
// exception must not abort the rest, and must be recorded so it surfaces). The
// guarantee lives in TenantScheduleSweep so it's unit-testable; here we just
// delegate.
$sweepTenants = fn (iterable $tenants, string $command, callable $onError): int => app(TenantScheduleSweep::class)->run($tenants, $command, $onError);

/*
|--------------------------------------------------------------------------
| Scheduled tasks  (config: config/ekdosi.php → 'schedule')
|--------------------------------------------------------------------------
|
| Replaces the legacy overnight FAutoInvoice batch. NOTHING here runs
| until the OS cron calls `php artisan schedule:run` every minute and a
| queue worker is up — see CLAUDE.md "Env-prep". All tasks are
| non-destructive (stage / read-only / crash-recovery).
|
| DB queries live INSIDE the closures (run-time), never at file load —
| this file is parsed on every artisan invocation, including migrate. The
| same rule is why the enable switch above is a `->when()` filter.
|
| withoutOverlapping(30): BOUNDED lock TTL (minutes). The default is 24h, so a
| run killed mid-flight (reboot / deploy / OOM) orphans the cache lock and every
| later schedule:run SILENTLY skips the task for a full day — which is exactly
| how the WHMCS fetch went dark for ~1.5 days. 30 min lets an orphaned lock
| self-heal fast; all tasks here are idempotent so a rare real overlap is benign.
|
*/

// mail-log:sweep-orphans — recover rows stuck by a crashed worker.
$trackSchedule(
    Schedule::command('mail-log:sweep-orphans')
        ->everyFifteenMinutes()
        ->when(fn () => $scheduleEnabled('mail_sweep_enabled'))
        ->withoutOverlapping(30),
    'mail_sweep'
);

// OPS-001: scheduler tick — write a heartbeat SYNCHRONOUSLY every minute, inside
// schedule:run itself (no queue worker). This is the «ο cron ζει» proof: if the OS
// crontab isn't calling `php artisan schedule:run` this key goes stale, and
// ops:health can then say «cron down» rather than blaming the worker. Deliberately
// UNGATED (unlike every other task) — it's the heartbeat that detects a dead cron,
// so a flag must not be able to silence it. Cost: one tiny cache write per minute
// (a single-row upsert on the DB cache driver used on prod), no queue involved.
Schedule::call(fn () => app(HealthRecorder::class)->recordSchedulerHeartbeat())
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Queue worker heartbeat — dispatch a tiny queued job. The health command only
// turns green when a real worker picks it up and writes the heartbeat.
Schedule::job(new RecordQueueHeartbeat)
    ->everyFiveMinutes()
    ->name('queue-worker-heartbeat')
    ->when(fn () => $scheduleEnabled('queue_heartbeat_enabled'))
    ->withoutOverlapping(10);

// invoices:resend-failed-emails — re-queue invoice emails whose last attempt
// failed. Default OFF (two-key: this flag); run manually until SMTP is healthy.
$trackSchedule(
    Schedule::command('invoices:resend-failed-emails', [
        '--since' => config('ekdosi.schedule.resend_failed_emails_since_days', 3),
    ])
        ->cron($scheduleCron('resend_failed_emails_cron', '30 * * * *'))
        ->name('resend-failed-emails')
        ->when(fn () => $scheduleEnabled('resend_failed_emails_enabled'))
        ->withoutOverlapping(30),
    'resend_failed_emails'
);

// model:prune (App\Models\AuthEvent) — trim the auth/security log past its
// retention window (ekdosi.auth_events_retention_days). No-op until rows age
// out; daily, off-peak. Scoped to AuthEvent so it never touches other models.
$trackSchedule(
    Schedule::command('model:prune', ['--model' => [AuthEvent::class]])
        ->cron($scheduleCron('prune_auth_events_cron', '20 3 * * *'))
        ->name('prune-auth-events')
        ->when(fn () => $scheduleEnabled('prune_auth_events_enabled'))
        ->withoutOverlapping(30),
    'prune_auth_events'
);

// whmcs:fetch-pending — stage paid+unfiled WHMCS invoices into the inbox,
// once per WHMCS-configured tenant. Operator-gated: this only STAGES,
// it never files at AADE.
$trackSchedule(
    Schedule::call(function () use ($sweepTenants) {
        $sweepTenants(
            Company::query()
                ->whereNotNull('whmcs_api_url')
                ->where('whmcs_api_url', '!=', '')
                ->get(),
            'whmcs:fetch-pending',
            fn (Company $c) => app(HealthRecorder::class)->recordWhmcsFetch($c, 1),
        );
    })
        ->cron($scheduleCron('whmcs_fetch_cron', '*/5 * * * *'))
        ->name('whmcs-fetch-all')
        ->when(fn () => $scheduleEnabled('whmcs_fetch_enabled'))
        ->withoutOverlapping(30),
    'whmcs_fetch'
);

// whmcs:fetch-unpaid — stage the UNPAID invoices of «τιμολόγιο-πριν-την-πληρωμή»
// customers (customers.needs_invoice_before_payment) into the inbox for MANUAL
// επί-πιστώσει issuance. Like the paid fetch it only STAGES — never files, never
// auto-issues. OFF by default (opt-in secondary fetch); unpaid invoices don't
// change fast, so a low cadence (hourly default) is plenty.
$trackSchedule(
    Schedule::call(function () use ($sweepTenants) {
        $sweepTenants(
            Company::query()
                ->whereNotNull('whmcs_api_url')
                ->where('whmcs_api_url', '!=', '')
                ->get(),
            'whmcs:fetch-unpaid',
            fn () => null,   // report($e) already logs an uncaught throw; no separate health key for this opt-in fetch
        );
    })
        ->cron($scheduleCron('whmcs_fetch_unpaid_cron', '0 * * * *'))
        ->name('whmcs-fetch-unpaid-all')
        ->when(fn () => $scheduleEnabled('whmcs_fetch_unpaid_enabled'))
        ->withoutOverlapping(30),
    'whmcs_fetch_unpaid'
);

// whmcs:auto-issue — auto-FILE paid inbox rows for γκρινιάρης customers on
// tenants that armed it (companies.whmcs_auto_issue_immediate). UNLIKE the
// fetch above, this files at AADE, so it's a two-key arming: this scheduler
// flag (default OFF) AND the per-tenant toggle. The command loops every
// armed tenant itself + leaves anything ambiguous in the inbox for a human.
// Tracked: this is the ONLY task that FILES at AADE unattended — its health
// must be visible (OPS-8).
$trackSchedule(
    Schedule::command('whmcs:auto-issue')
        ->cron($scheduleCron('whmcs_auto_issue_cron', '*/15 * * * *'))
        ->name('whmcs-auto-issue-all')
        ->when(fn () => $scheduleEnabled('whmcs_auto_issue_enabled'))
        ->withoutOverlapping(30),
    'whmcs_auto_issue'
);

// tickets:poll-imap — poll each support-enabled tenant's mail-configured
// department mailboxes and route inbound email into tickets (Πυλώνας E). Reads
// only, files nothing at AADE; the command isolates each department. OFF by
// default — arm per deploy once the mailbox config is verified live.
$trackSchedule(
    Schedule::command('tickets:poll-imap')
        ->cron($scheduleCron('tickets_poll_imap_cron', '*/5 * * * *'))
        ->name('tickets-poll-imap')
        ->when(fn () => $scheduleEnabled('tickets_poll_imap_enabled'))
        ->withoutOverlapping(30),
    'tickets_poll_imap'
);

// whmcs:sync-payments — INBOUND payment sync: close an ekdosi receivable when
// its WHMCS-linked invoice (issued επί πιστώσει) gets paid in WHMCS. The command
// loops every WHMCS-configured tenant itself. Money-write in ekdosi only +
// idempotent (only-if-open + dedup), but OFF by default (opt-in per deploy).
$trackSchedule(
    Schedule::command('whmcs:sync-payments')
        ->cron($scheduleCron('whmcs_payment_sync_cron', '*/30 * * * *'))
        ->name('whmcs-sync-payments-all')
        ->when(fn () => $scheduleEnabled('whmcs_payment_sync_enabled'))
        ->withoutOverlapping(30),
    'whmcs_payment_sync'
);

// whmcs:reconcile-payments — READ-ONLY detector feeding the dashboard widget +
// «Συγχρονισμός πληρωμών» page: which open επί-πιστώσει invoices did WHMCS pay?
// Caches the worklist + bell-notifies new items; writes no money. OFF by default.
$trackSchedule(
    Schedule::command('whmcs:reconcile-payments')
        ->cron($scheduleCron('whmcs_payment_reconcile_cron', '*/30 * * * *'))
        ->name('whmcs-reconcile-payments-all')
        ->when(fn () => $scheduleEnabled('whmcs_payment_reconcile_enabled'))
        ->withoutOverlapping(30),
    'whmcs_payment_reconcile'
);

// payments:expire-stale-intents — mark abandoned ONLINE portal payment intents
// «Έληξε» past the age threshold so «Εκκρεμείς Πληρωμές Πύλης» reflects reality.
// Cross-tenant, idempotent, non-destructive; a late verified capture still settles.
$trackSchedule(
    Schedule::command('payments:expire-stale-intents')
        ->cron($scheduleCron('intent_expiry_cron', '*/30 * * * *'))
        ->name('payments-expire-stale-intents')
        ->when(fn () => $scheduleEnabled('intent_expiry_enabled'))
        ->withoutOverlapping(30),
    'intent_expiry'
);

// mydata:reconcile-sales — daily read-only local↔AADE cross-check, once
// per myDATA-readable tenant (direct gr-mydata OR a provider reading its own
// AADE picture back). Discrepancies surface in the command output (exit 2);
// pipe schedule output to a log for alerting.
$trackSchedule(
    Schedule::call(function () use ($sweepTenants) {
        $sweepTenants(
            Company::myDataReadable(),
            'mydata:reconcile-sales',
            fn (Company $c) => app(HealthRecorder::class)->recordMyDataReconcile($c, 1),
        );
    })
        ->dailyAt($scheduleTime('mydata_reconcile_time', '06:00'))
        ->name('mydata-reconcile-all')
        ->when(fn () => $scheduleEnabled('mydata_reconcile_enabled'))
        ->withoutOverlapping(30),
    'mydata_reconcile'
);

// Refresh the cached dashboard "Εικόνα από myDATA" VAT snapshot (the widget
// reads the cache; this is the heavy AADE pull). All gr-mydata / non-Off
// tenants, every few hours.
$trackSchedule(
    Schedule::command('mydata:refresh-vat-picture')
        ->cron($scheduleCron('mydata_vat_picture_cron', '0 */4 * * *'))
        ->name('mydata-vat-picture-all')
        ->when(fn () => $scheduleEnabled('mydata_vat_picture_enabled'))
        ->withoutOverlapping(30),
    'mydata_vat_picture'
);

// dashboard:warm-metrics — pre-builds the cached «Αναφορές» metric slices per
// tenant (current + previous year), so the report widgets read a warm cache
// instead of each re-running its aggregates on open (~25s cold). Pure DB,
// READ-ONLY. Cadence stays ≤ the cache TTL (config 'dashboard.cache_ttl') so a
// warmed slice never expires onto a web page open.
$trackSchedule(
    Schedule::command('dashboard:warm-metrics')
        ->cron($scheduleCron('dashboard_metrics_cron', '0 */2 * * *'))
        ->name('dashboard-warm-metrics-all')
        ->when(fn () => $scheduleEnabled('dashboard_metrics_enabled'))
        ->withoutOverlapping(30),
    'dashboard_metrics'
);

// mydata:refresh-expenses — READ-ONLY refresh of the expenses reconciliation
// snapshot (keeps the Έξοδα worklist + «Άντληση» badge fresh). Creates no rows.
// Two-key: this deploy-wide flag enables the task (default OFF), and --auto-only
// limits the AUTOMATIC sweep to tenants that opted in via «Ρυθμίσεις εταιρείας»
// (companies.mydata_auto_fetch_expenses) — so a company_admin controls their own.
$trackSchedule(
    Schedule::command('mydata:refresh-expenses', ['--auto-only'])
        ->cron($scheduleCron('mydata_fetch_expenses_cron', '0 */6 * * *'))
        ->name('mydata-fetch-expenses-all')
        ->when(fn () => $scheduleEnabled('mydata_fetch_expenses_enabled'))
        ->withoutOverlapping(30),
    'mydata_fetch_expenses'
);

// mydata:refresh-console — warm ALL Κονσόλα myDATA snapshots (Πωλήσεις/Έξοδα/Ε3/
// εικόνα ΦΠΑ) per myDATA-readable tenant, so the console opens fresh. The heaviest
// AADE pull (four endpoints); default OFF. READ-ONLY (seeds caches, no rows). For a
// tenant that enables this, it supersedes the per-piece vat-picture / fetch-expenses
// tasks (it warms the same caches).
$trackSchedule(
    Schedule::command('mydata:refresh-console')
        ->cron($scheduleCron('mydata_console_refresh_cron', '0 */6 * * *'))
        ->name('mydata-console-refresh-all')
        ->when(fn () => $scheduleEnabled('mydata_console_refresh_enabled'))
        ->withoutOverlapping(30),
    'mydata_console_refresh'
);

// delivery:fetch-inbound — READ-ONLY staging of the ψηφιακή-διακίνηση docs OTHERS
// filed against us (goods we are RECEIVING) into «Εισερχόμενα Διακίνησης», per
// myDATA-readable tenant. Only stages — reject/confirm stay operator-gated inbox
// actions. Default ON (read-only; surfaced in the «Χρονοπρογραμματιστής» page).
$trackSchedule(
    Schedule::command('delivery:fetch-inbound')
        ->cron($scheduleCron('delivery_fetch_inbound_cron', '0 */6 * * *'))
        ->name('delivery-fetch-inbound-all')
        ->when(fn () => $scheduleEnabled('delivery_fetch_inbound_enabled'))
        ->withoutOverlapping(30),
    'delivery_fetch_inbound'
);

// suppliers:sync — build the Προμηθευτές μητρώο from myDATA RequestDocs issuer
// AFMs, once per myDATA-readable tenant (--tenant passed by TenantScheduleSweep).
// READ-from-AADE, write-ONLY-to-suppliers (idempotent — creates only missing
// rows; touches no invoice/expense/money). Default OFF (it writes master data —
// opt-in per deploy + per the page toggle). Window: the command's default (last
// month).
$trackSchedule(
    Schedule::call(function () use ($sweepTenants) {
        $sweepTenants(
            Company::myDataReadable(),
            'suppliers:sync',
            fn (Company $c) => null,
        );
    })
        ->cron($scheduleCron('suppliers_sync_cron', '0 4 * * *'))
        ->name('suppliers-sync-all')
        ->when(fn () => $scheduleEnabled('suppliers_sync_enabled'))
        ->withoutOverlapping(30),
    'suppliers_sync'
);

// customers:sync — the twin: build the Πελάτες μητρώο from the counterpart AFMs
// of our sales (RequestTransmittedDocs), per myDATA-readable tenant. Same
// READ-from-AADE, write-ONLY-to-customers, idempotent contract. Default OFF.
// Window: the command's default (last 12 months — a heavier AADE pull).
$trackSchedule(
    Schedule::call(function () use ($sweepTenants) {
        $sweepTenants(
            Company::myDataReadable(),
            'customers:sync',
            fn (Company $c) => null,
        );
    })
        ->cron($scheduleCron('customers_sync_cron', '30 4 * * *'))
        ->name('customers-sync-all')
        ->when(fn () => $scheduleEnabled('customers_sync_enabled'))
        ->withoutOverlapping(30),
    'customers_sync'
);

// invoices:notify-overdue — daily «bell» digest of ληξιπρόθεσμα τιμολόγια per
// tenant. Read-only, NO email; default OFF (opt-in per deploy).
$trackSchedule(
    Schedule::command('invoices:notify-overdue')
        ->dailyAt($scheduleTime('overdue_notifications_time', '07:30'))
        ->name('invoices-notify-overdue')
        ->when(fn () => $scheduleEnabled('overdue_notifications_enabled'))
        ->withoutOverlapping(30),
    'overdue_notifications'
);

// leads:notify-due — daily «bell» digest of leads whose «επόμενο βήμα» is due
// today / overdue, per tenant (assigned → its operator, unassigned → everyone).
// Read-only, NO email; default OFF (opt-in per deploy).
$trackSchedule(
    Schedule::command('leads:notify-due')
        ->dailyAt($scheduleTime('leads_notify_due_time', '08:00'))
        ->name('leads-notify-due')
        ->when(fn () => $scheduleEnabled('leads_notify_due_enabled'))
        ->withoutOverlapping(30),
    'leads_notify_due'
);

// services:stage-renewals — stage DRAFT renewal invoices for due service
// contracts, once per tenant (the command loops tenants itself). Default OFF:
// it creates real draft documents. Operator-gated downstream — drafts NEVER
// auto-file at AADE; they flow through the normal invoice lifecycle. lead_days
// stages contracts due within the next N days (early billing, default 0).
$trackSchedule(
    Schedule::command('services:stage-renewals', [
        '--lead-days' => config('ekdosi.schedule.service_renewals_lead_days', 0),
    ])
        ->dailyAt($scheduleTime('service_renewals_time', '07:00'))
        ->name('service-renewals')
        ->when(fn () => $scheduleEnabled('service_renewals_enabled'))
        ->withoutOverlapping(),
    'service_renewals'
);

// services:run-dunning — auto suspend/terminate overdue contracts (or unsuspend
// a paid one), once per tenant (the command loops tenants itself). Default ON,
// BUT the real on/off is the per-product dunning_enabled toggle (default OFF):
// a fresh deploy acts on nothing until an operator opts a product in. The
// command does NOT file at AADE — it only flips contract status + provisioning.
$trackSchedule(
    Schedule::command('services:run-dunning')
        ->dailyAt($scheduleTime('service_dunning_time', '08:00'))
        ->name('service-dunning')
        ->when(fn () => $scheduleEnabled('service_dunning_enabled'))
        ->withoutOverlapping(),
    'service_dunning'
);

// ai:dispatch-reminders — deliver due AI «Βοηθός» reminders (operator-confirmed)
// as Filament database notifications. Every minute so a reminder lands close to
// its time; idempotent (delivered_at gates re-delivery). Default ON.
Schedule::command('ai:dispatch-reminders')
    ->everyMinute()
    ->name('ai-dispatch-reminders')
    ->when(fn () => $scheduleEnabled('ai_reminders_enabled'))
    ->withoutOverlapping();

// spatie/laravel-backup tasks — disabled by config if a deployment runs them
// from systemd/cron directly, but tracked here when the Laravel scheduler owns them.
$trackSchedule(
    Schedule::command('backup:run')
        ->cron($scheduleCron('backup_run_cron', '0 2 * * *'))
        ->name('backup-run')
        ->when(fn () => $scheduleEnabled('backup_run_enabled'))
        ->withoutOverlapping(120),
    'backup_run'
);

$trackSchedule(
    Schedule::command('backup:clean')
        ->cron($scheduleCron('backup_cleanup_cron', '30 2 * * *'))
        ->name('backup-cleanup')
        ->when(fn () => $scheduleEnabled('backup_cleanup_enabled'))
        ->withoutOverlapping(120),
    'backup_cleanup'
);

$trackSchedule(
    Schedule::command('backup:monitor')
        ->cron($scheduleCron('backup_monitor_cron', '0 8 * * *'))
        ->name('backup-monitor')
        ->when(fn () => $scheduleEnabled('backup_monitor_enabled'))
        ->withoutOverlapping(30)
        ->onSuccess(fn () => app(HealthRecorder::class)->recordBackupMonitor(['status' => 'ok', 'exit_code' => 0]))
        ->onFailure(fn () => app(HealthRecorder::class)->recordBackupMonitor(['status' => 'failed', 'exit_code' => 1])),
    'backup_monitor'
);

// company:run-scheduled-backups — per-TENANT backups (Phase 4), distinct from
// the spatie whole-DB tasks above. Fires hourly; each company runs once per its
// own cadence (daily/weekly/monthly) at/after its configured time. Default OFF.
$trackSchedule(
    Schedule::command('company:run-scheduled-backups')
        ->cron($scheduleCron('company_backups_cron', '0 * * * *'))
        ->name('company-backups')
        ->when(fn () => $scheduleEnabled('company_backups_enabled'))
        ->withoutOverlapping(60),
    'company_backups'
);

// ekdosi:self-update — apply a queued in-app update/rollback OUT-OF-BAND (the
// update restarts the app, so it must not run in a web request or on the queue
// worker it restarts). Runs every minute but is a no-op unless a `queued`
// UpdateRun exists (the «Εγκατάσταση ενημέρωσης» / «Επαναφορά» actions create
// one). Single-flight via withoutOverlapping. Default ON — nothing runs until an
// operator triggers an update. See docs/versioning-and-updates.md.
$trackSchedule(
    Schedule::command('ekdosi:self-update', ['--pending' => true])
        ->everyMinute()
        ->name('self-update')
        ->when(fn () => $scheduleEnabled('self_update_enabled') && UpdateRun::hasPending())
        ->withoutOverlapping(30),
    'self_update'
);

// domains:sync — nightly registrar-truth pull (Πυλώνας A / A2b): expiry/status/
// NS for every syncable domain of domain-enabled tenants. READ-ONLY at the
// registrar. Default OFF (EKDOSI_SCHEDULE_DOMAIN_SYNC) — enable once a real
// registrar connection exists. Bounded via withoutOverlapping.
$trackSchedule(
    Schedule::command('domains:sync')
        ->cron($scheduleCron('domain_sync_cron', '0 5 * * *'))
        ->name('domains-sync')
        ->when(fn () => $scheduleEnabled('domain_sync_enabled'))
        ->withoutOverlapping(30),
    'domain_sync'
);

// customers:refresh-aade-status — periodic re-check of the AADE registry status
// (ενεργό/ανενεργό ΑΦΜ) of customers, bounded per run. READ-ONLY at GSIS. Default
// OFF (EKDOSI_SCHEDULE_AADE_STATUS_REFRESH) — opt in (respects GSIS rate limits).
// Weekly. Bounded via withoutOverlapping.
$trackSchedule(
    Schedule::command('customers:refresh-aade-status')
        ->cron($scheduleCron('aade_status_refresh_cron', '0 4 * * 1'))
        ->name('aade-status-refresh')
        ->when(fn () => $scheduleEnabled('aade_status_refresh_enabled'))
        ->withoutOverlapping(120),
    'aade_status_refresh'
);

// Hygiene: prune failed queue entries older than 14 days. Import jobs carry an
// ENCRYPTED Firebird password (see RunFirebirdImport), so failed_jobs never holds
// plaintext — but keeping the table bounded is still good practice.
Schedule::command('queue:prune-failed --hours=336')->daily();
