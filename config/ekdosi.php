<?php

use App\Services\Billing\Sources\WhmcsBillingSource;
use App\Services\EInvoice\Transports\InvoSignTransport;

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

        // queue-worker heartbeat — scheduled dispatch of a tiny queued job.
        // The worker is considered healthy only after the job is handled.
        'queue_heartbeat_enabled' => env('EKDOSI_SCHEDULE_QUEUE_HEARTBEAT', true),

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

        // spatie/laravel-backup tasks. Enable these when the Laravel scheduler
        // owns backups for the deployment; leave disabled if system cron/systemd
        // runs the backup commands separately.
        'backup_run_enabled' => env('EKDOSI_SCHEDULE_BACKUP_RUN', false),
        'backup_run_cron' => env('EKDOSI_BACKUP_RUN_CRON', '0 2 * * *'),
        'backup_cleanup_enabled' => env('EKDOSI_SCHEDULE_BACKUP_CLEANUP', false),
        'backup_cleanup_cron' => env('EKDOSI_BACKUP_CLEANUP_CRON', '30 2 * * *'),
        'backup_monitor_enabled' => env('EKDOSI_SCHEDULE_BACKUP_MONITOR', false),
        'backup_monitor_cron' => env('EKDOSI_BACKUP_MONITOR_CRON', '0 8 * * *'),

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

        // services:run-dunning — auto suspend/terminate contracts whose renewals
        // went overdue (or unsuspend a paid one), per tenant. Default ON: the
        // engine is "plugged in", BUT the REAL on/off is the per-product
        // dunning_enabled toggle (default OFF) — so a fresh deploy is a no-op
        // until an operator opts a product into dunning. HH:MM (server time).
        'service_dunning_enabled' => env('EKDOSI_SCHEDULE_SERVICE_DUNNING', true),
        'service_dunning_time' => env('EKDOSI_SERVICE_DUNNING_TIME', '08:00'),

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

    /*
    |--------------------------------------------------------------------------
    | Provisioning modules (recurring-services automation hooks)
    |--------------------------------------------------------------------------
    |
    | Map of provisioning-module key → ProvisioningModule implementation,
    | resolved by ProvisioningModuleRegistry. A contract's `provisioning_module`
    | key selects the module the dunning engine calls on suspend/unsuspend/
    | terminate. EMPTY by default — every key (incl. 'none'/'custom'/unknown)
    | falls back to NullProvisioningModule (local state only, no remote action).
    | A real cPanel/Mailcow/license-server module drops in here with one line +
    | one class (mirrors einvoice/billing registries). No core edit.
    |
    */
    'provisioning' => [
        'modules' => [
            // 'cpanel'  => App\Services\Provisioning\CpanelProvisioningModule::class,   // future
            // 'mailcow' => App\Services\Provisioning\MailcowProvisioningModule::class,  // future
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | E-invoice provider transports (ΥΠΑΗΕΣ)
    |--------------------------------------------------------------------------
    |
    | Map of provider key → EInvoiceProviderTransport implementation, resolved by
    | ProviderTransportRegistry. A tenant's companies.einvoice_provider_key selects
    | the transport a GrProviderSubmitter (P2) will POST the AADE document to.
    | EMPTY by default — every key (incl. 'none'/unknown) falls back to
    | NullProviderTransport (throws on send → never silently not-files). A real
    | provider (InvoSign / SBZ) drops in here with one line + one class once its
    | sandbox creds arrive (P5). No core edit. (Mirrors billing/provisioning.)
    |
    */
    'einvoice' => [
        'providers' => [
            'invosign' => InvoSignTransport::class,
            // 'sbz'   => App\Services\EInvoice\Transports\SbzTransport::class,           // P5+
        ],

        /*
        | Human labels for the operator "Τρόπος αποστολής" dropdown (P3). Each key
        | yields a "<label> — Δοκιμαστικό" + "<label> — Παραγωγή" pair. Listed here
        | so a provider is SELECTABLE (and its credentials enterable) before the
        | transport class is wired above — until then «Έλεγχος σύνδεσης»/filing
        | fail loudly via the Null transport (never silently). Add/remove a line to
        | show/hide a provider in the dropdown.
        */
        'provider_labels' => [
            'invosign' => 'InvoSign',
            'sbz' => 'SBZ',
        ],

        /*
        | Per-provider credential field schema for the operator form (P3): the
        | labeled inputs shown when that provider is selected (no raw JSON). Each
        | entry: key => ['label' => …, 'secret' => bool]. Stored into the encrypted
        | einvoice_provider_config blob. Tweak per provider's real API.
        */
        'provider_fields' => [
            'invosign' => [
                // Production (the «Παραγωγή» channel uses these).
                'base_url' => ['label' => 'Base URL (Παραγωγή)', 'secret' => false],
                'token' => ['label' => 'Token (Παραγωγή)', 'secret' => true],
                // Sandbox / demo (the «Δοκιμαστικό» channel uses these).
                'demo_base_url' => ['label' => 'Base URL (Δοκιμαστικό)', 'secret' => false],
                'demo_token' => ['label' => 'Token (Δοκιμαστικό)', 'secret' => true],
            ],
            'sbz' => [
                'base_url' => ['label' => 'Base URL', 'secret' => false],
                'api_key' => ['label' => 'API Key', 'secret' => true],
            ],
        ],
    ],

];
