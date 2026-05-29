<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduled tasks
    |--------------------------------------------------------------------------
    |
    | ekdosi's recurring jobs (registered in routes/console.php). NOTHING
    | actually runs until the OS cron invokes `php artisan schedule:run`
    | every minute AND a queue worker is running — those are the real
    | master switches. The flags below let an operator disable an
    | individual task without touching the cron.
    |
    | Defaults are conservative and non-destructive: the WHMCS fetch only
    | STAGES invoices into the operator-gated inbox (never files at AADE),
    | the myDATA reconcile is READ-ONLY, and the mail-log sweep only
    | recovers rows left stuck by a crashed worker.
    |
    */
    'schedule' => [

        // mail-log:sweep-orphans — flip mail rows stuck in queued/sending
        // (crashed worker) to failed once older than the threshold.
        'mail_sweep_enabled' => env('EKDOSI_SCHEDULE_MAIL_SWEEP', true),
        'mail_sweep_threshold_minutes' => (int) env('EKDOSI_MAIL_SWEEP_THRESHOLD', 15),

        // whmcs:fetch-pending — pull paid+unfiled WHMCS invoices into the
        // inbox, per WHMCS-configured tenant. Cron expression (default
        // every 15 min).
        'whmcs_fetch_enabled' => env('EKDOSI_SCHEDULE_WHMCS_FETCH', true),
        'whmcs_fetch_cron' => env('EKDOSI_WHMCS_FETCH_CRON', '*/15 * * * *'),

        // whmcs:auto-issue — auto-FILE paid inbox rows for γκρινιάρης
        // customers on armed tenants. UNLIKE the fetch above, this submits
        // to AADE unattended, so it's OFF by default — a deliberate two-key
        // arming (this flag AND companies.whmcs_auto_issue_immediate). Turn
        // on only after validating the per-tenant config live.
        'whmcs_auto_issue_enabled' => env('EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE', false),
        'whmcs_auto_issue_cron' => env('EKDOSI_WHMCS_AUTO_ISSUE_CRON', '*/15 * * * *'),

        // mydata:reconcile-sales — daily read-only local↔AADE cross-check,
        // per gr-mydata / non-Off tenant. HH:MM (server time).
        'mydata_reconcile_enabled' => env('EKDOSI_SCHEDULE_MYDATA_RECONCILE', true),
        'mydata_reconcile_time' => env('EKDOSI_MYDATA_RECONCILE_TIME', '06:00'),

        // mydata:refresh-vat-picture — caches the dashboard "Εικόνα από myDATA"
        // VAT snapshot (εκροές−εισροές) per gr-mydata tenant. Heavy AADE pull;
        // run every few hours so the widget reads a fresh-enough cache.
        'mydata_vat_picture_enabled' => env('EKDOSI_SCHEDULE_MYDATA_VAT_PICTURE', true),
        'mydata_vat_picture_cron' => env('EKDOSI_MYDATA_VAT_PICTURE_CRON', '0 */4 * * *'),

    ],

];
