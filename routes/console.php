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
*/

// mail-log:sweep-orphans — recover rows stuck by a crashed worker.
if (config('ekdosi.schedule.mail_sweep_enabled')) {
    Schedule::command('mail-log:sweep-orphans')
        ->everyFifteenMinutes()
        ->withoutOverlapping();
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
        ->withoutOverlapping();
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
        ->withoutOverlapping();
}
