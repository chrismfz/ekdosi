<?php

use App\Services\Billing\Sources\WhmcsBillingSource;

return [

    // Force every operator to set up TOTP 2FA on their next login. Default OFF
    // (2FA is opt-in via the profile page) so turning it on can't lock the team
    // out mid-flight — enrol everyone first, then flip EKDOSI_REQUIRE_2FA=true.
    'require_2fa' => (bool) env('EKDOSI_REQUIRE_2FA', false),

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

        // invoices:resend-failed-emails — re-queue invoice emails whose last
        // send attempt failed. Default OFF: a systemic mail outage would
        // otherwise re-queue en masse every run; enable once SMTP is healthy.
        'resend_failed_emails_enabled' => env('EKDOSI_SCHEDULE_RESEND_FAILED_EMAILS', false),
        'resend_failed_emails_cron' => env('EKDOSI_RESEND_FAILED_EMAILS_CRON', '30 * * * *'),
        'resend_failed_emails_since_days' => (int) env('EKDOSI_RESEND_FAILED_EMAILS_SINCE', 3),

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

        // invoices:notify-overdue — daily «bell» digest of ληξιπρόθεσμα per
        // tenant (NO email). Default OFF so a fresh deploy doesn't surprise
        // operators with notifications until they opt in. HH:MM (server time).
        'overdue_notifications_enabled' => env('EKDOSI_SCHEDULE_OVERDUE_NOTIFICATIONS', false),
        'overdue_notifications_time' => env('EKDOSI_OVERDUE_NOTIFICATIONS_TIME', '07:30'),

        // services:stage-renewals — stage DRAFT renewal invoices for due
        // service contracts, per tenant. Default OFF: it creates real draft
        // documents, so enable per deploy once the catalogue + contracts are
        // set up. Operator-gated downstream (drafts never auto-file at AADE).
        // lead_days>0 stages contracts due within the next N days (early
        // billing). HH:MM (server time).
        'service_renewals_enabled' => env('EKDOSI_SCHEDULE_SERVICE_RENEWALS', false),
        'service_renewals_time' => env('EKDOSI_SERVICE_RENEWALS_TIME', '07:00'),
        'service_renewals_lead_days' => (int) env('EKDOSI_SERVICE_RENEWALS_LEAD_DAYS', 0),

    ],

    /*
    |--------------------------------------------------------------------------
    | Billing sources (Bridges / Connectors)
    |--------------------------------------------------------------------------
    |
    | Phase 0 seam (docs/bridges-connectors.md): the map of billing-source key →
    | BillingSource implementation, resolved by BillingSourceRegistry. A tenant
    | registers which sources it connects to in the `billing_connections` table;
    | this map says how each source key behaves (label + capabilities today,
    | fetch/write-back in Phase 1). Add a new source = one line here + one class
    | (mirrors einvoice provider routing). No core edit.
    |
    */
    'billing' => [
        'sources' => [
            'whmcs' => WhmcsBillingSource::class,
            // 'woocommerce' => App\Services\Billing\Sources\WooCommerceBillingSource::class,  // Phase 1+
            // 'blesta'      => App\Services\Billing\Sources\BlestaBillingSource::class,        // Phase 1+
        ],
    ],

];
