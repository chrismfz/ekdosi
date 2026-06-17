<?php

use App\Services\Backup\Destinations\FtpBackupDestination;
use App\Services\Backup\Destinations\LocalBackupDestination;
use App\Services\Backup\Destinations\S3BackupDestination;
use App\Services\Backup\Destinations\SftpBackupDestination;
use App\Services\Billing\Sources\WhmcsBillingSource;
use App\Services\EInvoice\Transports\InvoSignTransport;

return [

    // Force every operator to set up TOTP 2FA on their next login. Default OFF
    // (2FA is opt-in via the profile page) so turning it on can't lock the team
    // out mid-flight — enrol everyone first, then flip EKDOSI_REQUIRE_2FA=true.
    'require_2fa' => (bool) env('EKDOSI_REQUIRE_2FA', false),

    /*
    |--------------------------------------------------------------------------
    | Secrets at-rest encryption (DR / «work without APP_KEY»)
    |--------------------------------------------------------------------------
    |
    | When FALSE (default), the secret columns (per-tenant myDATA/GSIS/SMTP/WHMCS
    | keys, server creds, 2FA secrets, backup passphrase) are stored as PLAINTEXT
    | in the DB — so a plain mysqldump is self-sufficient and a DR restore on a
    | new VM does NOT need the old APP_KEY. Protection then rests on DB/disk access
    | control (the DB is the trust boundary). Set TRUE to keep them encrypted at
    | rest under APP_KEY (the classic posture — but then DR must carry the key).
    |
    | The `App\Casts\MaybeEncrypted` cast ALWAYS decrypts legacy ciphertext on
    | read, so flipping this flag never breaks existing rows; run
    | `php artisan secrets:reencrypt --to=plain|encrypted` to rewrite them.
    |
    */
    'secrets' => [
        'encrypt_at_rest' => (bool) env('EKDOSI_ENCRYPT_SECRETS_AT_REST', false),
    ],

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

        // mydata:refresh-expenses — READ-ONLY refresh of the expenses
        // reconciliation snapshot (current quarter) per myDATA-readable tenant, so
        // the «Κονσόλα myDATA — Έξοδα» worklist + the «Άντληση» badge on the Έξοδα
        // list stay fresh. Creates NO expense rows (import stays operator-gated) →
        // safe, but default OFF (opt-in per deploy; it's a recurring AADE pull).
        'mydata_fetch_expenses_enabled' => env('EKDOSI_SCHEDULE_MYDATA_FETCH_EXPENSES', false),
        'mydata_fetch_expenses_cron' => env('EKDOSI_MYDATA_FETCH_EXPENSES_CRON', '0 */6 * * *'),

        // mydata:refresh-console — warms ALL «Κονσόλα myDATA» snapshots (Πωλήσεις /
        // Έξοδα / Ε3 / Εικόνα ΦΠΑ) for the current quarter per myDATA-readable
        // tenant, so the console opens with fresh data instead of a stale-or-empty
        // cache. The heaviest AADE pull of the lot (four endpoints) → default OFF;
        // it SUPERSEDES the per-piece vat-picture / fetch-expenses tasks for a
        // tenant that turns it on. READ-ONLY (creates no rows).
        'mydata_console_refresh_enabled' => env('EKDOSI_SCHEDULE_MYDATA_CONSOLE_REFRESH', false),
        'mydata_console_refresh_cron' => env('EKDOSI_MYDATA_CONSOLE_REFRESH_CRON', '0 */6 * * *'),

        // spatie/laravel-backup tasks. Enable these when the Laravel scheduler
        // owns backups for the deployment; leave disabled if system cron/systemd
        // runs the backup commands separately.
        'backup_run_enabled' => env('EKDOSI_SCHEDULE_BACKUP_RUN', false),
        'backup_run_cron' => env('EKDOSI_BACKUP_RUN_CRON', '0 2 * * *'),
        'backup_cleanup_enabled' => env('EKDOSI_SCHEDULE_BACKUP_CLEANUP', false),
        'backup_cleanup_cron' => env('EKDOSI_BACKUP_CLEANUP_CRON', '30 2 * * *'),
        'backup_monitor_enabled' => env('EKDOSI_SCHEDULE_BACKUP_MONITOR', false),
        'backup_monitor_cron' => env('EKDOSI_BACKUP_MONITOR_CRON', '0 8 * * *'),

        // company:run-scheduled-backups — per-TENANT backup pipeline (Phase 4),
        // distinct from the spatie whole-DB tasks above. Runs hourly and fires
        // each company whose own cadence (company_backup_settings) is due.
        // Default OFF (a fresh deploy shouldn't start writing bundles until an
        // operator configures destinations + retention per company).
        'company_backups_enabled' => env('EKDOSI_SCHEDULE_COMPANY_BACKUPS', false),
        'company_backups_cron' => env('EKDOSI_COMPANY_BACKUPS_CRON', '0 * * * *'),

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
    | Company backups (Phase 4) — per-tenant backup destinations
    |--------------------------------------------------------------------------
    |
    | The map of destination key → BackupDestination implementation, resolved by
    | BackupDestinationRegistry (mirrors the billing/einvoice registries). A
    | company picks one or more of these in its company_backup_settings. `local`
    | is always available (the Download source + retention target); SFTP / FTP /
    | S3 land in Slice 4b — add a line here + one class, no core edit.
    | `local_disk` is the Laravel filesystem disk the local artifacts live on.
    |
    */
    'backup' => [
        'local_disk' => env('EKDOSI_BACKUP_LOCAL_DISK', 'local'),
        // Email an ops address when a SCHEDULED per-company backup ends
        // failed/partial (the unattended path — manual runs surface status in the
        // UI). Comma-separated; if empty we fall back to the super_admin users,
        // and always Log::error regardless. Toggle the whole thing off here.
        'alert_on_failure' => env('EKDOSI_BACKUP_ALERT_ON_FAILURE', true),
        'alert_email' => env('EKDOSI_BACKUP_ALERT_EMAIL'),
        'destinations' => [
            'local' => LocalBackupDestination::class,
            'sftp' => SftpBackupDestination::class,
            'ftp' => FtpBackupDestination::class,
            's3' => S3BackupDestination::class,
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

    /*
    |--------------------------------------------------------------------------
    | AI «Βοηθός» (assistant)
    |--------------------------------------------------------------------------
    | Phase-1 read-only in-app chat. The global feature switch + the default
    | model + the per-model price map (USD per 1M tokens) the ai_usage_log cost
    | estimate is computed from. Per-company on/off, model and cap live on the
    | `companies` row; this is the deploy-wide defaults + economics.
    */
    'ai' => [
        // Master switch — even an ai_assistant_enabled tenant stays dark if off.
        'enabled' => env('EKDOSI_AI_ENABLED', false),

        // Default model when a tenant hasn't picked one. Sonnet = the sweet spot
        // for tool-use operator chat (see docs/ai-assistant-blueprint.md).
        'default_model' => env('EKDOSI_AI_DEFAULT_MODEL', 'claude-sonnet-4-6'),

        // Per-turn safety rails.
        'max_tokens' => (int) env('EKDOSI_AI_MAX_TOKENS', 1024),
        'max_tool_iterations' => (int) env('EKDOSI_AI_MAX_TOOL_ITERATIONS', 6),
        'timeout' => (int) env('EKDOSI_AI_TIMEOUT', 60),

        // Global backstop cap (tokens/tenant/month) independent of any per-tenant
        // cap — a runaway-loop net. 0 = no global cap.
        'global_monthly_token_cap' => (int) env('EKDOSI_AI_GLOBAL_TOKEN_CAP', 5_000_000),

        // USD per 1,000,000 tokens. cache_read ≈ 0.1× input, cache_write ≈ 1.25×.
        // Refresh on Anthropic price changes; cost is our estimate, reconciled to
        // the single monthly invoice.
        'pricing' => [
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
        ],
    ],

];
