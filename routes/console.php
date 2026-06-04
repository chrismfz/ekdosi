<?php

use App\Models\Company;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
| this file is parsed on every artisan invocation, including migrate.
|
| withoutOverlapping(30): BOUNDED lock TTL (minutes). The default is 24h, so a
| run killed mid-flight (reboot / deploy / OOM) orphans the cache lock and every
| later schedule:run SILENTLY skips the task for a full day — which is exactly
| how the WHMCS fetch went dark for ~1.5 days. 30 min lets an orphaned lock
| self-heal fast; all tasks here are idempotent so a rare real overlap is benign.
|
*/

// mail-log:sweep-orphans — recover rows stuck by a crashed worker.
if (config('ekdosi.schedule.mail_sweep_enabled')) {
    Schedule::command('mail-log:sweep-orphans')
        ->everyFifteenMinutes()
        ->withoutOverlapping(30);
}

// invoices:resend-failed-emails — re-queue invoice emails whose last attempt
// failed. Default OFF (two-key: this flag); run manually until SMTP is healthy.
if (config('ekdosi.schedule.resend_failed_emails_enabled')) {
    Schedule::command('invoices:resend-failed-emails', [
        '--since' => config('ekdosi.schedule.resend_failed_emails_since_days', 3),
    ])
        ->cron(config('ekdosi.schedule.resend_failed_emails_cron', '30 * * * *'))
        ->withoutOverlapping(30);
}

// whmcs:fetch-pending — stage paid+unfiled WHMCS invoices into the inbox,
// once per WHMCS-configured tenant. Operator-gated: this only STAGES,
// it never files at AADE.
if (config('ekdosi.schedule.whmcs_fetch_enabled')) {
    Schedule::call(function () {
        Company::query()
            ->whereNotNull('whmcs_api_url')
            ->where('whmcs_api_url', '!=', '')
            ->get()
            ->each(fn (Company $c) => Artisan::call('whmcs:fetch-pending', ['--tenant' => $c->slug]));
    })
        ->cron(config('ekdosi.schedule.whmcs_fetch_cron', '*/15 * * * *'))
        ->name('whmcs-fetch-all')
        ->withoutOverlapping(30);
}

// whmcs:auto-issue — auto-FILE paid inbox rows for γκρινιάρης customers on
// tenants that armed it (companies.whmcs_auto_issue_immediate). UNLIKE the
// fetch above, this files at AADE, so it's a two-key arming: this scheduler
// flag (default OFF) AND the per-tenant toggle. The command loops every
// armed tenant itself + leaves anything ambiguous in the inbox for a human.
if (config('ekdosi.schedule.whmcs_auto_issue_enabled')) {
    Schedule::command('whmcs:auto-issue')
        ->cron(config('ekdosi.schedule.whmcs_auto_issue_cron', '*/15 * * * *'))
        ->name('whmcs-auto-issue-all')
        ->withoutOverlapping(30);
}

// mydata:reconcile-sales — daily read-only local↔AADE cross-check, once
// per Greek / non-Off tenant. Discrepancies surface in the command output
// (exit 2); pipe schedule output to a log for alerting.
if (config('ekdosi.schedule.mydata_reconcile_enabled')) {
    Schedule::call(function () {
        Company::query()
            ->where('einvoice_provider', 'gr-mydata')
            ->where('mydata_mode', '!=', 'off')
            ->get()
            ->each(fn (Company $c) => Artisan::call('mydata:reconcile-sales', ['--tenant' => $c->slug]));
    })
        ->dailyAt(config('ekdosi.schedule.mydata_reconcile_time', '06:00'))
        ->name('mydata-reconcile-all')
        ->withoutOverlapping(30);
}

// Refresh the cached dashboard "Εικόνα από myDATA" VAT snapshot (the widget
// reads the cache; this is the heavy AADE pull). All gr-mydata / non-Off
// tenants, every few hours.
if (config('ekdosi.schedule.mydata_vat_picture_enabled')) {
    Schedule::command('mydata:refresh-vat-picture')
        ->cron(config('ekdosi.schedule.mydata_vat_picture_cron', '0 */4 * * *'))
        ->name('mydata-vat-picture-all')
        ->withoutOverlapping(30);
}

// invoices:notify-overdue — daily «bell» digest of ληξιπρόθεσμα τιμολόγια per
// tenant. Read-only, NO email; default OFF (opt-in per deploy).
if (config('ekdosi.schedule.overdue_notifications_enabled')) {
    Schedule::command('invoices:notify-overdue')
        ->dailyAt(config('ekdosi.schedule.overdue_notifications_time', '07:30'))
        ->name('invoices-notify-overdue')
        ->withoutOverlapping(30);
}

// services:stage-renewals — stage DRAFT renewal invoices for due service
// contracts, once per tenant (the command loops tenants itself). Default OFF:
// it creates real draft documents. Operator-gated downstream — drafts NEVER
// auto-file at AADE; they flow through the normal invoice lifecycle. lead_days
// stages contracts due within the next N days (early billing, default 0).
if (config('ekdosi.schedule.service_renewals_enabled')) {
    Schedule::command('services:stage-renewals', [
        '--lead-days' => config('ekdosi.schedule.service_renewals_lead_days', 0),
    ])
        ->dailyAt(config('ekdosi.schedule.service_renewals_time', '07:00'))
        ->name('service-renewals')
        ->withoutOverlapping();
}

// services:run-dunning — auto suspend/terminate overdue contracts (or unsuspend
// a paid one), once per tenant (the command loops tenants itself). Default ON,
// BUT the real on/off is the per-product dunning_enabled toggle (default OFF):
// a fresh deploy acts on nothing until an operator opts a product in. The
// command does NOT file at AADE — it only flips contract status + provisioning.
if (config('ekdosi.schedule.service_dunning_enabled')) {
    Schedule::command('services:run-dunning')
        ->dailyAt(config('ekdosi.schedule.service_dunning_time', '08:00'))
        ->name('service-dunning')
        ->withoutOverlapping();
}
