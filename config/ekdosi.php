<?php

use App\Services\Backup\Destinations\FtpBackupDestination;
use App\Services\Backup\Destinations\LocalBackupDestination;
use App\Services\Backup\Destinations\S3BackupDestination;
use App\Services\Backup\Destinations\SftpBackupDestination;
use App\Services\Billing\Sources\WhmcsBillingSource;
use App\Services\EInvoice\Transports\InvoSignTransport;
use App\Services\Payments\Gateways\ManualPaymentGateway;

return [

    // Force every operator to set up TOTP 2FA on their next login. Default OFF
    // (2FA is opt-in via the profile page) so turning it on can't lock the team
    // out mid-flight — enrol everyone first, then flip EKDOSI_REQUIRE_2FA=true.
    'require_2fa' => (bool) env('EKDOSI_REQUIRE_2FA', false),

    /*
    |--------------------------------------------------------------------------
    | Demo seed (SET-1)
    |--------------------------------------------------------------------------
    |
    | `db:seed` (DatabaseSeeder) builds a throwaway «DEMO Α.Ε.» tenant + a demo
    | super_admin — for a fresh clone / reviewer. This must NEVER run on a real
    | host (it would create a super_admin with a well-known password). Real
    | installs use `php artisan ekdosi:install`. Gate the demo seed behind an
    | EXPLICIT opt-in — not app()->isProduction() alone, because a prod box was
    | once mislabeled APP_ENV=local, which would leave an env-based guard OFF
    | exactly when it mattered. Opt-in is prod-safe regardless of APP_ENV.
    |
    */
    'seed_demo' => (bool) env('EKDOSI_SEED_DEMO', false),
    'seed_demo_password' => (string) env('EKDOSI_SEED_DEMO_PASSWORD', 'password'),

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
        // SEC-1 (AUDIT): plaintext-at-rest is a DELIBERATE trade-off, but it must
        // be a CONSCIOUS one. When secrets are NOT encrypted, go-live-check WARNS
        // until this is set true — an explicit "yes, we accept plaintext-at-rest;
        // the DB/backup access control is our trust boundary" acknowledgement
        // (see docs/security-at-rest.md). Ignored when encrypt_at_rest = true.
        'plaintext_acknowledged' => (bool) env('EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unhandled-exception alerting (OPS-3)
    |--------------------------------------------------------------------------
    |
    | Without this, every production exception goes ONLY to storage/logs/
    | laravel.log — which nobody watches — so a wedged myDATA submit, a
    | throwing observer or a dying scheduler task fails silently. When enabled,
    | a reportable unhandled exception ALSO emails the ops recipients (deduped
    | per signature within `throttle_minutes` so an error loop can't flood the
    | inbox). It NEVER suppresses the normal log line, and it's best-effort —
    | a mail hiccup can't break the request/command.
    |
    | Recipients: `email` (comma-separated) if set, else the same chain the
    | backup alerts use (EKDOSI_BACKUP_ALERT_EMAIL → super_admins).
    |
    */
    'error_alerts' => [
        'enabled' => (bool) env('EKDOSI_ERROR_ALERTS', true),
        'email' => env('EKDOSI_ERROR_ALERT_EMAIL'),
        'throttle_minutes' => (int) env('EKDOSI_ERROR_ALERT_THROTTLE_MINUTES', 30),
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

        // ekdosi:self-update — apply a queued in-app update/rollback out-of-band.
        // No-op unless a run is actually queued, so it's safe ON by default; turn
        // OFF to freeze in-app updates entirely.
        'self_update_enabled' => env('EKDOSI_SCHEDULE_SELF_UPDATE', true),

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

        // whmcs:fetch-unpaid — stage the UNPAID invoices of «τιμολόγιο πριν την
        // πληρωμή» customers (needs_invoice_before_payment) into the inbox for
        // MANUAL επί-πιστώσει issuance. Never files, never auto-issues. OFF by
        // default (opt-in secondary fetch); unpaid invoices don't change fast, so a
        // low cadence (hourly) is plenty.
        'whmcs_fetch_unpaid_enabled' => env('EKDOSI_SCHEDULE_WHMCS_FETCH_UNPAID', false),
        'whmcs_fetch_unpaid_cron' => env('EKDOSI_WHMCS_FETCH_UNPAID_CRON', '0 * * * *'),

        // whmcs:auto-issue — auto-FILE paid inbox rows for γκρινιάρης
        // customers on armed tenants. UNLIKE the fetch above, this submits
        // to AADE unattended, so it's OFF by default — a deliberate two-key
        // arming (this flag AND companies.whmcs_auto_issue_immediate). Turn
        // on only after validating the per-tenant config live.
        'whmcs_auto_issue_enabled' => env('EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE', false),
        'whmcs_auto_issue_cron' => env('EKDOSI_WHMCS_AUTO_ISSUE_CRON', '*/15 * * * *'),

        // whmcs:sync-payments — INBOUND payment sync: record an ekdosi Payment
        // for a filed WHMCS-linked invoice that is still OPEN (issued επί
        // πιστώσει) and has since been paid in WHMCS. Money-write in ekdosi only
        // (never the customer's WHMCS); idempotent (only-if-open + dedup). OFF by
        // default — opt in per deploy once the credit-term flow is live.
        'whmcs_payment_sync_enabled' => env('EKDOSI_SCHEDULE_WHMCS_PAYMENT_SYNC', false),
        'whmcs_payment_sync_cron' => env('EKDOSI_WHMCS_PAYMENT_SYNC_CRON', '*/30 * * * *'),

        // whmcs:reconcile-payments — READ-ONLY detector: recompute the worklist
        // of open «επί πιστώσει» invoices that WHMCS now reports Paid, cache it
        // for the dashboard widget + «Συγχρονισμός πληρωμών» page, and bell-notify
        // new ones. Writes NO money (the operator closes each with one click). OFF
        // by default — opt in to surface the worklist «εύκαιρα» without the console.
        'whmcs_payment_reconcile_enabled' => env('EKDOSI_SCHEDULE_WHMCS_PAYMENT_RECONCILE', false),
        'whmcs_payment_reconcile_cron' => env('EKDOSI_WHMCS_PAYMENT_RECONCILE_CRON', '*/30 * * * *'),

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

        // spatie/laravel-backup tasks — the WHOLE-DB (all tenants + files)
        // safety net, distinct from the per-company backups below.
        //
        // AUDIT OPS-1: these default ON. They used to default OFF, and
        // INSTALL.md never said to flip them — so a host provisioned by the
        // book ran with ZERO automated DB backups. Like every scheduled task
        // they only fire once the OS cron runs `schedule:run` (inert in
        // dev/CI), and a local nightly dump is strictly better than none.
        // Off-site replication is a one-liner on top: BACKUP_DESTINATION_DISKS
        // (config/backup.php). Set the env flags to false only if system
        // cron/systemd runs the backup commands separately.
        'backup_run_enabled' => env('EKDOSI_SCHEDULE_BACKUP_RUN', true),
        'backup_run_cron' => env('EKDOSI_BACKUP_RUN_CRON', '0 2 * * *'),
        'backup_cleanup_enabled' => env('EKDOSI_SCHEDULE_BACKUP_CLEANUP', true),
        'backup_cleanup_cron' => env('EKDOSI_BACKUP_CLEANUP_CRON', '30 2 * * *'),
        'backup_monitor_enabled' => env('EKDOSI_SCHEDULE_BACKUP_MONITOR', true),
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

        // leads:notify-due — daily «bell» digest of leads whose «επόμενο βήμα»
        // is due today or overdue, per tenant (assigned → its operator,
        // unassigned → everyone; NO email). Default OFF (opt-in per deploy).
        // HH:MM (server time).
        'leads_notify_due_enabled' => env('EKDOSI_SCHEDULE_LEADS_NOTIFY_DUE', false),
        'leads_notify_due_time' => env('EKDOSI_LEADS_NOTIFY_DUE_TIME', '08:00'),

        // ai:dispatch-reminders — deliver due AI «Βοηθός» reminders (the bell).
        // Default ON: a confirmed reminder is expected to fire (still inert until
        // the OS cron + a queue worker run the scheduler). Cheap every-minute
        // sweep so a reminder lands close to its time.
        'ai_reminders_enabled' => env('EKDOSI_SCHEDULE_AI_REMINDERS', true),

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
    | Payment gateways (Πυλώνας B) — docs/payment-gateways-design.md
    |--------------------------------------------------------------------------
    |
    | The map of gateway key → PaymentGateway implementation, resolved by
    | PaymentGatewayRegistry. A tenant enables/configures which ones it offers in
    | the `payment_gateway_connections` table («Τρόποι πληρωμής» admin). Add a
    | gateway = one line here + one class (mirrors the einvoice/billing registries);
    | no core edit, no migration. B0 ships only the offline `manual` gateway.
    |
    */
    'payments' => [
        'gateways' => [
            'manual' => ManualPaymentGateway::class,
            'eurobank' => App\Services\Payments\Gateways\EurobankGateway::class,   // B1 (vPOS: card + Apple/Google Pay + IRIS)
            // 'paypal'   => App\Services\Payments\Gateways\PaypalGateway::class,    // B2
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
        | MYD-2 (AUDIT, σκέλος γ) — "in-doubt" retry grace, in MINUTES.
        |
        | On a TRANSPORT failure (timeout / connection drop) a submission is
        | AMBIGUOUS: the POST may have reached AADE and produced a MARK we never
        | saw. AADE does NOT dedup a resubmission of the same (series, ΑΑ)
        | (sandbox-proven 2026-07-07: same invoiceUid → two MARKs), so a blind
        | retry double-declares income. The next submit() reconciles first:
        |   - a live MARK found  → ADOPT it (never a second filing);
        |   - NOTHING found      → could be "never landed" OR "not yet indexed",
        |     because RequestTransmittedDocs lags a freshly-filed doc by a minute
        |     or two (sandbox-observed). So within this grace window we REFUSE to
        |     resubmit (operator waits + retries); only AFTER it elapses with AADE
        |     still empty do we treat the earlier POST as lost and file normally.
        | The daily reconcile remains the backstop for anything that slips through.
        */
        'in_doubt_grace_minutes' => (int) env('EKDOSI_MYDATA_INDOUBT_GRACE_MINUTES', 10),

        /*
        | PROV-009 — low-quota threshold for a ΥΠΑΗΕΣ provider account. Providers
        | (InvoSign) return the account's REMAINING QUOTA on every issue response
        | (`remaining_invoices`), so we track it for free. At or below this many
        | remaining filings a warning is logged on each new filing, and the
        | ProviderQuotaStats dashboard widget turns warning/danger — nudging the
        | operator to top up before the account runs dry mid-day.
        */
        'provider_low_quota_threshold' => (int) env('EKDOSI_PROVIDER_LOW_QUOTA_THRESHOLD', 50),

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
            // `url => true` marks an endpoint field validated by ProviderEndpointGuard
            // (public https only, no SSRF) at the form, preflight and transport — so a
            // renamed/new URL field is guarded by declaration, not a name-suffix guess.
            'invosign' => [
                // Production (the «Παραγωγή» channel uses these).
                'base_url' => ['label' => 'Base URL (Παραγωγή)', 'secret' => false, 'url' => true],
                'token' => ['label' => 'Token (Παραγωγή)', 'secret' => true],
                // Sandbox / demo (the «Δοκιμαστικό» channel uses these).
                'demo_base_url' => ['label' => 'Base URL (Δοκιμαστικό)', 'secret' => false, 'url' => true],
                'demo_token' => ['label' => 'Token (Δοκιμαστικό)', 'secret' => true],
            ],
            'sbz' => [
                'base_url' => ['label' => 'Base URL', 'secret' => false, 'url' => true],
                'api_key' => ['label' => 'API Key', 'secret' => true],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Provider legal identity (PROV-003) — for the printed representation
        |----------------------------------------------------------------------
        | A.1112/2025 requires a provider-issued document's PRINTOUT to carry the
        | provider's identity + licence. Keyed by mydata_marks.provider_key; read
        | via App\Support\EInvoice\ProviderIdentity and rendered on the invoice PDF
        | when the filing MARK is a PROVIDER_INSERT. A new provider adds one row.
        |
        | InvoSign: AADE-listed provider code 030, licence
        | 2025_05_130GVSolutions_001_iNVO Sign_V1_07052025. All values below are
        | sourced from the AADE licensed-provider register + invosign.gr. `legal_name`
        | is left EMPTY on purpose: the exact registered entity is not yet confirmed,
        | and the PDF omits an empty legal_name rather than print an unverified legal
        | identity on a legal document. Fill it once the provider confirms the entity.
        */
        'provider_identity' => [
            'invosign' => [
                'commercial_name' => 'iNVO Sign',
                'legal_name' => '', // TODO: confirm the registered entity, then fill.
                'site' => 'https://invosign.gr',
                'aade_code' => '030',
                'licence_no' => '2025_05_130GVSolutions_001_iNVO Sign_V1_07052025',
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

        // Per-incident throttle: max sends/minute per user+company.
        'rate_per_minute' => (int) env('EKDOSI_AI_RATE_PER_MINUTE', 15),

        // Prompt caching (automatic): caches the stable prefix (tools+system+
        // history); cache reads are 0.1× input. Transparent cost optimisation —
        // no behaviour change, no-op below the min cache size. Default ON.
        'prompt_cache' => (bool) env('EKDOSI_AI_PROMPT_CACHE', true),

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

    /*
    |--------------------------------------------------------------------------
    | Update check (Phase 1: READ-ONLY)
    |--------------------------------------------------------------------------
    | A read-only «είναι το κουτί ενημερωμένο;» check: compares the deployed
    | build against the repo's latest GitHub release/tag and shows «N πίσω» +
    | a link on the super_admin System page. It NEVER applies an update — the
    | actual upgrade stays with deploy/update.sh (snapshot → migrate →
    | queue:restart → ops:health). A one-click apply that WRAPS that script is
    | a deliberate later phase.
    |
    | Token: needed only for a PRIVATE repo (GitHub API auth). Public repos work
    | unauthenticated within the 6h cache. Never write scope — a read-only PAT.
    */
    'updates' => [
        'enabled' => (bool) env('EKDOSI_UPDATE_CHECK', true),
        'repo' => (string) env('EKDOSI_UPDATE_REPO', 'chrismfz/ekdosi'),
        'token' => env('EKDOSI_UPDATE_TOKEN', env('GITHUB_TOKEN')),
        'cache_hours' => (int) env('EKDOSI_UPDATE_CACHE_HOURS', 6),
        'timeout' => (int) env('EKDOSI_UPDATE_TIMEOUT', 8),

        // In-app APPLY — OFF by default (UPD-001…015, triage 2026-09-02).
        //
        // The CHECK above stays on: knowing a release exists is useful and
        // read-only. Applying one from a web request is not: the audit found the
        // orchestration does not drain the queue worker it restarts, fails open
        // after a partial apply (maintenance lifted over inconsistent code),
        // targets a MUTABLE tag rather than a verified commit SHA, and can start
        // without a proven rollback path. Those are deploy-time correctness
        // problems, not UI polish.
        //
        // The SUPPORTED upgrade path is `deploy/update.sh <tag>` on the host,
        // which already owns the snapshot → maintenance → checkout → composer →
        // migrate → optimize → shield → queue:restart → ops:health sequence, with
        // deploy/rollback.sh behind it. «Υγεία συστήματος» now says exactly that
        // when a release is available.
        //
        // Turning this on re-arms the in-app button and the scheduler's apply of a
        // queued run. Only do that once UPD-001…004 are actually fixed — the flag
        // exists so the machinery is not deleted, not because it is ready.
        'allow_in_app_apply' => (bool) env('EKDOSI_UPDATE_IN_APP_APPLY', false),

        // 'php' (portable, no root — shared hosting + VPS) or 'script' (wrap
        // deploy/update.sh on a VPS that has the shell tooling). Only consulted
        // when allow_in_app_apply is on.
        'strategy' => (string) env('EKDOSI_UPDATE_STRATEGY', 'php'),
    ],

];
