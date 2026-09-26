<?php

namespace App\Models;

use App\Casts\MaybeEncrypted;
use App\Enums\MyDataMode;
use App\Models\Scopes\CompanyScope;
use App\Observers\CompanyObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

#[ObservedBy(CompanyObserver::class)]
class Company extends Model
{
    use HasFactory;

    /**
     * Stage B-3: the two path fragments that couple ekdosi's
     * outbound-bridge URL derivation to the WHMCS-side plugin's
     * filesystem layout. Kept as named constants (not inline magic
     * strings) so the coupling is grep-discoverable: if the WHMCS
     * plugin's inbound.php is ever moved/renamed, this is the ONE
     * place ekdosi needs to change. The plugin's inbound.php docblock
     * cross-references this constant.
     */
    public const WHMCS_API_PATH_SUFFIX = '/includes/api.php';

    public const WHMCS_BRIDGE_PATH = '/modules/addons/ekdosi_bridge/inbound.php';

    protected $fillable = [
        'name',
        'slug',
        // Multi-domain #1c: optional custom portal host (e.g. cs.nixpal.com).
        'portal_host',
        'country_code',
        // i18n Slice 0: fallback document/mail language for this tenant (null|el|en|both;
        // null = app default el). App\Support\CustomerLanguage falls back here.
        'default_language',
        'einvoice_provider',
        'einvoice_provider_key',
        'einvoice_provider_config',
        'einvoice_provider_mode',
        'einvoice_include_customer_email',
        'afm',
        'tax_office',
        'kad_primary',
        // MYD-006: business-activity policy (reseller/manufacturer/services/mixed) —
        // decides the income-classification bucket (§8.6) of GOODS lines.
        'business_activity_type',
        'gemi',
        'address',
        'city',
        'postcode',
        // Latin-script identity for international transport docs (CMR sender).
        'name_en',
        'address_en',
        'city_en',
        'phone',
        'email',
        'mydata_aade_id_sandbox',
        'mydata_subscription_key_sandbox',
        'mydata_aade_id_production',
        'mydata_subscription_key_production',
        'mydata_mode',
        // Explicit READ-environment override (null = follow the submit mode);
        // lets a provider tenant testing in sandbox READ production myDATA.
        'mydata_read_env',
        // AI «Βοηθός» per-company governance (see ai-assistant-blueprint.md).
        'ai_assistant_enabled',
        'ai_model',
        'ai_monthly_token_cap',
        'ai_api_key',
        // Πυλώνας E — Support/Ticket pillar per-tenant kill-switch (default off).
        'support_enabled',
        // Πυλώνας A — Domains pillar per-tenant kill-switch (default off).
        'enable_domain_management',
        // Προσωπικό / ΕΡΓΑΝΗ pillar per-tenant kill-switch (default off) + the
        // ΕΡΓΑΝΗ ΙΙ environment and e-ΕΦΚΑ credentials (docs/ergani/README.md).
        'ergani_enabled',
        'ergani_mode',
        'ergani_username',
        'ergani_password',
        // Φάση 2: explicit opt-in to submit approved leaves (WTOLeave) — creds alone never do.
        'ergani_submit_leaves',
        // Φάση 3: Ψηφιακή Κάρτα Εργασίας (WRKCardSE) opt-in + «μόνο μέσω QR γραφείου».
        'ergani_submit_cards',
        'ergani_submit_overtime',
        'ergani_card_requires_kiosk',
        // Opt-in: also transmit the per-line description (<itemDescr>) to myDATA.
        'mydata_send_item_descr',
        'gsis_username',
        'gsis_password',
        // #8: per-tenant opt-in for the weekly AADE ΑΦΜ-status re-check (gentle,
        // bounded). The knob lives next to the GSIS credentials.
        'aade_status_auto_refresh',
        // PR #27: branding + outbound mail config
        'logo_path',
        'pdf_footer_text',
        // «Υπόλοιπο πελάτη» on the invoice PDF — per-tenant default (off).
        'show_customer_balance_on_pdf',
        'mail_from_address',
        'leave_notify_email',
        'mail_from_name',
        'invoice_audit_bcc',
        'auto_email_on_mydata_accept',
        'auto_email_on_issue',
        'mydata_auto_fetch_expenses',
        // Payment reminders (dunning) — self-service via CompanySettings.
        'reminders_enabled',
        'reminders_mode',
        'reminders_since',
        'reminder_pre_due_days',
        'reminder_first_days',
        'reminder_second_days',
        'reminder_final_days',
        'reminder_min_balance',
        'reminder_attach_pdf',
        'reminder_templates',
        'mail_smtp_host',
        'mail_smtp_port',
        'mail_smtp_username',
        'mail_smtp_password',
        'mail_smtp_encryption',
        'mail_subject_template',
        'mail_body_template',
        // PR #28: WHMCS bridge — Stage A credentials
        'whmcs_api_url',
        'whmcs_api_identifier',
        'whmcs_api_secret',
        'whmcs_custom_field_map',
        // PR #31: WHMCS bridge — Stage B-1 inbound webhook secret
        'whmcs_webhook_secret',
        // PR #34 followup: cutover date - skip WHMCS invoices older than this
        'whmcs_invoice_min_date',
        // T-1b: per-tenant backend kill-switch for third-party invoicing
        'whmcs_third_party_enabled',
        'whmcs_amount_includes_tax',
        // Slice 2: fetch the inbox feed from the bridge plugin (not the native API)
        'whmcs_fetch_via_bridge',
        // Phase 2 (outbound): push an ekdosi settlement back to WHMCS as Paid (opt-in)
        'whmcs_push_payments',
        // G8 phase 2: άμεση-τιμολόγηση auto-issue knob + its default invoice/receipt types
        'whmcs_auto_issue_immediate',
        'whmcs_default_invoice_type_id',
        'whmcs_default_receipt_type_id',
        'whmcs_default_unpaid_type_id',
    ];

    /**
     * Secret columns kept OUT of array/JSON serialization (toArray/toJson/logs/API)
     * — defence-in-depth now that they're plaintext at rest by default
     * (ekdosi.secrets.encrypt_at_rest). Attribute access ($company->gsis_password)
     * and the admin Company form (which re-injects them explicitly) are unaffected.
     *
     * @var list<string>
     */
    protected $hidden = [
        'mydata_subscription_key_sandbox',
        'mydata_subscription_key_production',
        'einvoice_provider_config',
        'gsis_password',
        'mail_smtp_password',
        'whmcs_api_secret',
        'whmcs_webhook_secret',
        'ai_api_key',
        // ΕΡΓΑΝΗ e-ΕΦΚΑ password.
        'ergani_password',
    ];

    protected function casts(): array
    {
        return [
            'mydata_subscription_key_sandbox' => MaybeEncrypted::class,
            'mydata_subscription_key_production' => MaybeEncrypted::class,
            // Provider credential blob — JSON (api key / token / endpoint /
            // provider AFM + ΥΠΑΗΕΣ licence no.). Same at-rest pattern as the keys above.
            'einvoice_provider_config' => MaybeEncrypted::class.':array',
            'einvoice_include_customer_email' => 'boolean',
            'gsis_password' => MaybeEncrypted::class,
            'mail_smtp_password' => MaybeEncrypted::class,
            'whmcs_api_secret' => MaybeEncrypted::class,
            'ai_assistant_enabled' => 'boolean',
            'support_enabled' => 'boolean',
            'enable_domain_management' => 'boolean',
            'ergani_enabled' => 'boolean',
            'ergani_submit_leaves' => 'boolean',
            'ergani_submit_cards' => 'boolean',
            'ergani_submit_overtime' => 'boolean',
            'ergani_card_sector' => 'boolean',
            'ergani_card_sector_checked_at' => 'datetime',
            'ergani_production_since' => 'datetime',
            'ergani_roster_diff' => 'array',
            'ergani_roster_checked_at' => 'datetime',
            'ergani_card_requires_kiosk' => 'boolean',
            'ergani_password' => MaybeEncrypted::class,
            'ai_monthly_token_cap' => 'integer',
            'ai_api_key' => MaybeEncrypted::class,
            'whmcs_webhook_secret' => MaybeEncrypted::class,
            'whmcs_custom_field_map' => 'array',
            'income_tax_profile' => 'array',
            'whmcs_invoice_min_date' => 'date',
            'whmcs_third_party_enabled' => 'boolean',
            'aade_status_auto_refresh' => 'boolean',
            'whmcs_amount_includes_tax' => 'boolean',
            'whmcs_fetch_via_bridge' => 'boolean',
            'whmcs_push_payments' => 'boolean',
            'whmcs_auto_issue_immediate' => 'boolean',
            'mydata_send_item_descr' => 'boolean',
            'show_customer_balance_on_pdf' => 'boolean',
            'auto_email_on_mydata_accept' => 'boolean',
            'auto_email_on_issue' => 'boolean',
            'mydata_auto_fetch_expenses' => 'boolean',
            'reminders_enabled' => 'boolean',
            'reminders_since' => 'date',
            'reminder_pre_due_days' => 'integer',
            'reminder_first_days' => 'integer',
            'reminder_second_days' => 'integer',
            'reminder_final_days' => 'integer',
            'reminder_min_balance' => 'decimal:2',
            'reminder_attach_pdf' => 'boolean',
            'reminder_templates' => 'array',
            'mail_smtp_port' => 'integer',
        ];
    }

    /**
     * True iff this tenant has WHMCS integration configured. Empty
     * url disables the bridge entirely (UI hidden, scheduled pulls
     * skip the tenant). Identifier + secret are checked here too
     * because a half-configured tenant should NOT see the integration
     * as "active" — both halves are required.
     */
    public function hasWhmcsIntegration(): bool
    {
        return ! empty($this->whmcs_api_url)
            && ! empty($this->whmcs_api_identifier)
            && ! empty($this->whmcs_api_secret);
    }

    /**
     * True iff the Support/Ticket pillar (Πυλώνας E) is enabled for this tenant.
     * Default off — gates the Support Cluster, its config screens and the portal
     * «Τα αιτήματά μου». A super-admin flips it on the Company form.
     */
    public function hasSupport(): bool
    {
        return (bool) $this->support_enabled;
    }

    /**
     * True iff the Domains pillar (Πυλώνας A) is enabled for this tenant.
     * Default off — gates the Domains cluster and everything in it. A
     * super-admin flips it on the Company form. Design: docs/domains/README.md.
     */
    public function hasDomainManagement(): bool
    {
        return (bool) $this->enable_domain_management;
    }

    /**
     * True iff the Προσωπικό / ΕΡΓΑΝΗ pillar (leaves, calendar, employees,
     * holidays) is enabled for this tenant. Default off — a super-admin flips it
     * in the «ΕΡΓΑΝΗ» tab of the Company form.
     */
    public function hasErgani(): bool
    {
        return (bool) $this->ergani_enabled;
    }

    /**
     * Resolve a tenant from a CLI "--tenant" argument that may be a slug or
     * a numeric id. Shared by the myDATA / WHMCS console commands so the
     * lookup isn't copy-pasted (and inconsistent) per command.
     */
    public static function findBySlugOrId(?string $arg): ?self
    {
        if ($arg === null || $arg === '') {
            return null;
        }

        return static::query()
            ->where(fn ($q) => $q
                ->where('slug', $arg)
                ->orWhere('id', is_numeric($arg) ? (int) $arg : 0))
            ->first();
    }

    /**
     * Normalise a portal host to a bare lowercase hostname — trims, lowercases, and
     * strips an accidental scheme / path / port — or null when blank. Shared by the
     * `portal_host` mutator, {@see resolveByPortalHost()}, and the CompanyResource
     * form so all three agree (a pasted `https://cs.nixpal.com/x` still resolves to
     * `cs.nixpal.com` and matches `request()->getHost()`).
     */
    public static function normalizePortalHost(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));

        if ($host === '') {
            return null;
        }

        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }

        $host = explode('/', $host)[0];   // drop any path
        $host = explode(':', $host)[0];   // drop any port

        return $host === '' ? null : $host;
    }

    /**
     * Resolve the tenant that owns a custom portal host (multi-domain #1c), or
     * null for the shared default host / an unknown host. Normalises the incoming
     * host the same way `portal_host` is stored so the match is robust.
     */
    public static function resolveByPortalHost(?string $host): ?self
    {
        $host = self::normalizePortalHost($host);

        if ($host === null) {
            return null;
        }

        return static::query()->where('portal_host', $host)->first();
    }

    /**
     * Derive the URL of the ekdosi_bridge plugin's inbound endpoint
     * from the tenant's whmcs_api_url. The plugin lives at a fixed
     * path relative to the WHMCS root:
     *
     *   {whmcs_root}/modules/addons/ekdosi_bridge/inbound.php
     *
     * The convention works because WHMCS's native API is always at
     * {whmcs_root}/includes/api.php — we strip that suffix and
     * append the plugin's path. If the operator's WHMCS install
     * doesn't follow this convention (custom paths, behind a
     * reverse proxy with different path mapping), we'd need to
     * add an explicit companies.whmcs_bridge_url column — defer
     * until a real deployment surfaces that need.
     *
     * Stage B-3: Returns null when whmcs_api_url is empty (tenant
     * has no WHMCS integration at all) OR when the URL doesn't
     * follow the expected api.php convention (we refuse to guess
     * a path; operator must configure properly).
     */
    public function whmcsBridgeUrl(): ?string
    {
        $api = trim((string) ($this->whmcs_api_url ?? ''));
        if ($api === '') {
            return null;
        }
        // Normalise before matching the suffix: operators routinely
        // paste the URL with a trailing slash (copy from a docs page)
        // or a stray ?query. Strip both so the well-known
        // /includes/api.php convention still matches — otherwise the
        // bridge silently disables itself on a cosmetic typo.
        $api = preg_replace('/[?#].*$/', '', $api);   // drop query / fragment
        $api = rtrim($api, '/');                       // drop trailing slashes

        $suffix = self::WHMCS_API_PATH_SUFFIX;
        if (! str_ends_with($api, $suffix)) {
            return null;
        }
        $base = substr($api, 0, -strlen($suffix));

        return $base.self::WHMCS_BRIDGE_PATH;
    }

    /**
     * Resolve a canonical WHMCS-bridge role name to the tenant's
     * actual WHMCS custom field id. Returns null when the role
     * isn't mapped (operator hasn't filled in this row's id) —
     * callers should treat null as "this WHMCS install doesn't
     * track that field; skip the lookup, don't throw."
     *
     * Canonical roles (per CLAUDE.md WHMCS-bridge prep notes):
     *   vatno      — customer AFM
     *   taxoffice    — ΔΟΥ
     *   occupation   — Δραστηριότητα
     *   griniaris    — immediate-invoice flag (custom-field boolean)
     *   wantsinvoice — "θα ήθελα τιμολόγιο" checkbox: invoice-vs-receipt intent
     *   toinvoice    — alternative company-name-to-bill
     */
    public function whmcsCustomFieldId(string $role): ?int
    {
        $map = $this->whmcs_custom_field_map ?? [];
        if (! isset($map[$role])) {
            return null;
        }

        // JSON ints come back as int; defensive cast in case operators
        // typed "13" as a string in a hand-edited row.
        return (int) $map[$role] ?: null;
    }

    /**
     * True iff this tenant has its own SMTP server configured. The
     * Mailer factory checks this to decide whether to swap the runtime
     * config or fall through to the global MAIL_MAILER. Host alone is
     * the signal — username/password are optional (some local relays
     * accept unauthenticated sends from trusted IPs).
     */
    public function hasOwnSmtp(): bool
    {
        return ! empty($this->mail_smtp_host);
    }

    /**
     * Parsed list of audit-BCC recipients. The column is a single
     * comma/semicolon-separated string (legacy operators typed it as
     * `"audit@example.com, ops@example.com"`); we split and trim.
     * Returns an empty array if the column is empty — the mailer
     * skips the BCC header entirely in that case.
     *
     * @return list<string>
     */
    public function auditBccList(): array
    {
        $raw = (string) ($this->invoice_audit_bcc ?? '');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            // Defensive: drop anything that doesn't even look like an
            // email — silently. The CompanyForm has a validator that
            // surfaces format errors at save time, so an invalid value
            // arriving here is either pre-PR-27 data or a hand-edit
            // and the right behaviour is "best-effort send, skip junk"
            // not "explode the queue worker on every invoice".
            if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * Normalise `einvoice_provider_key` ON WRITE — the ONE place the key is cleaned.
     *
     * `ProviderTransportRegistry::for()` trims before resolving, and the resolved
     * transport is what stamps `mydata_marks.provider_key` — so a key stored with
     * stray whitespace FILES fine while every place that compares the raw column
     * disagrees with it. Measured consequences of a `' invosign '` row:
     *   - `SendChannel::fromCompany()` composes `' invosign -sandbox'`, which is not
     *     in the dropdown's options → the Company form is UNSAVEABLE (the Select
     *     renders blank and fails validation on every save, even for unrelated edits);
     *   - `ViewInvoice`'s payload preview and `einvoice:provider-test-submit` compare
     *     `=== 'invosign'` and miss → they show un-augmented AADE XML while the REAL
     *     filing is augmented (the preview lies);
     *   - the dashboard quota card queries `mydata_marks.provider_key` with the raw
     *     value and finds nothing → «καμία υποβολή ακόμη» for a tenant filing daily.
     * Normalising here fixes all of them at once, instead of trimming at N readers
     * (tried in PR #518 and backed out — a half-normalised codebase hides the rest).
     *
     * Empty → null, so `empty()`/`filled()` gates read a whitespace-only key as
     * «δεν έχει οριστεί πάροχος» rather than as a configured one.
     */
    protected function einvoiceProviderKey(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => ($trimmed = trim((string) $value)) === '' ? null : $trimmed,
        );
    }

    /**
     * Custom portal host (#1c). Normalise on write — lowercase + trimmed, empty →
     * null — so it matches `request()->getHost()` (always lowercase) and so a
     * blank field reads as «no custom host» rather than an empty configured one.
     */
    protected function portalHost(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => self::normalizePortalHost($value),
        );
    }

    /**
     * Typed accessor for mydata_mode. The column stores the raw string
     * for backward compatibility with ETL / artisan / DB-direct paths
     * (and so a new value added to MyDataMode doesn't break older rows);
     * code paths that want to switch on the mode should read this
     * accessor instead of the raw column.
     */
    protected function mydataModeEnum(): Attribute
    {
        return Attribute::make(
            // Parens around the ?? so the coalesce binds BEFORE the cast.
            // Without them, `(string) $this->attributes['mydata_mode'] ?? ''`
            // parses as `((string) $this->attributes['mydata_mode']) ?? ''`
            // which fires "Undefined array key" if mydata_mode isn't in
            // the loaded attributes (e.g. a partial Company::select(['id'])
            // query) before ?? gets a chance to short-circuit.
            get: fn () => MyDataMode::tryFrom((string) ($this->attributes['mydata_mode'] ?? '')) ?? MyDataMode::Off,
        );
    }

    /**
     * Resolve the myDATA REST credentials for a given submission mode.
     *
     * We keep TWO independent credential pairs on the tenant — one for
     * the AADE sandbox/developer endpoint, one for production/live — so
     * an operator flips `mydata_mode` to switch environments WITHOUT
     * re-keying the aade-user-id / subscription-key each time.
     *
     * Off has no endpoint of its own; it falls back to the sandbox pair
     * for the few read-only audit callers that ask, but the submitter /
     * reconciler guards reject Off long before any AADE call is made.
     *
     * @param  MyDataMode|null  $mode  Defaults to the tenant's current mode.
     * @return array{0: ?string, 1: ?string} [aadeUserId, subscriptionKey]
     */
    public function mydataCredentials(?MyDataMode $mode = null): array
    {
        $mode ??= $this->mydata_mode_enum;

        return $mode === MyDataMode::Production
            ? [$this->mydata_aade_id_production, $this->mydata_subscription_key_production]
            : [$this->mydata_aade_id_sandbox, $this->mydata_subscription_key_sandbox];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Is this a live myDATA tenant — a Greek (`gr-mydata`) tenant whose mode is
     * not Off? The single home for the "can this tenant reach AADE" predicate
     * that the myDATA console / Ε3 / MARK-detail pages gate their access on
     * (previously copy-pasted into each page's canAccess()).
     */
    public function isLiveMyDataTenant(): bool
    {
        return $this->einvoice_provider === 'gr-mydata'
            && $this->mydata_mode_enum !== MyDataMode::Off;
    }

    /** True when this tenant files through a certified ΥΠΑΗΕΣ provider (mode ≠ off). */
    public function isLiveProviderTenant(): bool
    {
        return $this->einvoice_provider === 'gr-provider'
            && ($this->einvoice_provider_mode ?? 'off') !== 'off';
    }

    /** True when the tenant files electronically through ANY channel (direct myDATA OR a provider). */
    public function submitsElectronically(): bool
    {
        return $this->isLiveMyDataTenant() || $this->isLiveProviderTenant();
    }

    /**
     * Does this tenant file to AADE by PROVIDER — Greek myDATA directly (`gr-mydata`)
     * or via a Greek e-invoicing provider (`gr-provider`)? Provider-gated and mode-
     * INDEPENDENT on purpose: it answers «is this an AADE tenant whose §8-code config
     * must be valid», which a mode=off `gr-mydata` tenant still is (the mapping must be
     * ready BEFORE go-live flips the mode on). The single source for that gate — the
     * config audit (MYD-4), the Preflight report and the Payment-Methods §8.12 column
     * all read it, so they never drift on «who is an AADE tenant».
     */
    public function filesToAadeByProvider(): bool
    {
        return in_array($this->einvoice_provider, ['gr-mydata', 'gr-provider'], true);
    }

    /**
     * Which myDATA environment do we READ from, or null when this tenant can't
     * read from myDATA at all. ORTHOGONAL to submission (see canReadMyData()).
     *
     * - Explicit override (`mydata_read_env` = 'sandbox' | 'production') wins,
     *   decoupling the read environment from the submit channel/mode. This is the
     *   one lever for the otherwise-impossible case: a tenant submitting via a
     *   provider's SANDBOX (InvoSign Δοκιμαστικό) that wants to READ its real
     *   PRODUCTION myDATA picture. Honoured only when the chosen environment has a
     *   populated read credential slot; otherwise we fall through to the automatic
     *   rule below rather than silently reading nothing. Null (the default) = auto.
     * - gr-mydata (auto): the submission mode IS the read mode; Off → null.
     * - gr-provider (auto): the provider does the SUBMITTING, but the tenant still
     *   reads its OWN ΑΦΜ documents back with its own myDATA subscription.
     *   mydata_mode is 'off' for providers (the channel form clears it), so the
     *   read environment follows `einvoice_provider_mode` — the sandbox/production
     *   twin the whole provider stack keys off (ProviderCredentials::fromCompany,
     *   EInvoiceSubmitterFactory) — so reads land on the SAME environment the
     *   tenant submits to, never split-brain. Only an explicit 'production'
     *   prefers the live read subscription. Unlike SUBMISSION (which fail-safes
     *   to sandbox to never accidentally file against production), a READ never
     *   writes to AADE, so when the preferred slot is empty we fall back to the
     *   other populated slot — a tenant mid-migration (provider in sandbox but
     *   only its old production read subscription set) still sees its real
     *   picture rather than losing it.
     *
     * Checks only the plain aade-id columns — no key decryption here, so
     * navigation never crashes on a rotated APP_KEY; the key is validated at
     * call time (FirebedCredentials::init) with a friendly message.
     */
    public function mydataReadMode(): ?MyDataMode
    {
        // Only the two Greek channels read from myDATA at all.
        if (! in_array($this->einvoice_provider, ['gr-mydata', 'gr-provider'], true)) {
            return null;
        }

        // Explicit override — honoured only when that environment has credentials
        // (else fall through to auto, so a mis-set override never blanks the read).
        if (($override = $this->honouredMydataReadEnvOverride()) !== null) {
            return $override;
        }

        if ($this->einvoice_provider === 'gr-mydata') {
            return $this->mydata_mode_enum === MyDataMode::Off
                ? null
                : $this->mydata_mode_enum;
        }

        // gr-provider: follow einvoice_provider_mode, falling back to the populated slot.
        $order = ($this->einvoice_provider_mode ?? 'off') === 'production'
            ? [MyDataMode::Production, MyDataMode::Sandbox]
            : [MyDataMode::Sandbox, MyDataMode::Production];

        foreach ($order as $mode) {
            if (filled($this->{self::mydataReadIdColumn($mode)})) {
                return $mode;
            }
        }

        return null;
    }

    /** The plain aade-user-id column backing a READ environment (never Off). */
    private static function mydataReadIdColumn(MyDataMode $mode): string
    {
        return $mode === MyDataMode::Production
            ? 'mydata_aade_id_production'
            : 'mydata_aade_id_sandbox';
    }

    /**
     * The explicit `mydata_read_env` override IFF it is actually honoured — a valid
     * sandbox/production value whose aade-id slot is populated; null otherwise (unset,
     * or set to a slot with no read credentials, in which case mydataReadMode() uses
     * the auto rule). The ONE definition of "did the override win", shared by
     * mydataReadMode() and the mydata_settings diagnostic so the two never drift.
     * Checks only the plain aade-id column — no key decryption (rotated-APP_KEY-safe).
     */
    public function honouredMydataReadEnvOverride(): ?MyDataMode
    {
        $override = MyDataMode::tryFrom((string) ($this->mydata_read_env ?? ''));
        if (($override === MyDataMode::Sandbox || $override === MyDataMode::Production)
            && filled($this->{self::mydataReadIdColumn($override)})) {
            return $override;
        }

        return null;
    }

    /**
     * Can this tenant READ its own picture from myDATA (RequestTransmittedDocs /
     * RequestDocs / RequestE3Info)? The single home for the "can this tenant
     * reach AADE for READS" predicate that the read-only consoles + widgets gate
     * on (vs `isLiveMyDataTenant()`, which is the submit-side gate). A provider
     * tenant keeps it because the documents are still its own — it just doesn't
     * file them directly.
     */
    public function canReadMyData(): bool
    {
        return $this->mydataReadMode() !== null;
    }

    /**
     * All tenants that can READ from myDATA, as a Collection. Read-eligibility
     * (canReadMyData) depends on a populated credential slot, which isn't a
     * clean SQL predicate (a provider's mydata_mode is 'off'), so we narrow to
     * the two Greek channels in SQL and filter in PHP. The single source for the
     * scheduled myDATA read jobs (refresh-vat-picture, reconcile-sales) +
     * OperatorHealth, so they never drift from the dashboard widget's gate. The
     * tenant count is a handful, so the full scan is irrelevant.
     *
     * @return Collection<int, static>
     */
    public static function myDataReadable(): Collection
    {
        return static::query()
            ->whereIn('einvoice_provider', ['gr-mydata', 'gr-provider'])
            ->get()
            ->filter(fn (self $c) => $c->canReadMyData())
            ->values();
    }

    /** Short human label for the active e-invoice channel — for invoice action labels. */
    public function einvoiceChannelLabel(): string
    {
        if ($this->isLiveProviderTenant()) {
            $labels = (array) config('ekdosi.einvoice.provider_labels', []);
            $name = $labels[$this->einvoice_provider_key] ?? ($this->einvoice_provider_key ?: 'Πάροχο');

            return 'Πάροχο ('.$name.')';
        }

        return 'myDATA';
    }

    /**
     * Operators with access to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * G8 phase 2: the invoice type the άμεση-τιμολόγηση auto-issue uses. Null =
     * not configured → auto-issue skips this tenant (never guesses).
     */
    public function defaultWhmcsInvoiceType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class, 'whmcs_default_invoice_type_id');
    }

    /** Phase 4: this company's automated-backup policy (one row). */
    public function backupSetting(): HasOne
    {
        return $this->hasOne(CompanyBackupSetting::class);
    }

    /** Phase 4: the company's backup-run audit log (newest first). */
    public function backupRuns(): HasMany
    {
        return $this->hasMany(CompanyBackupRun::class)->latest('started_at');
    }

    /**
     * How many legal documents this company has already FILED (MYD-024).
     *
     * The AADE issuer block on an invoice is only vatNumber + country + branch, so
     * a name/address change cannot alter a filed invoice's payload — but the ΑΦΜ
     * can, and it is also the credential identity: changing it means the myDATA
     * account, the provider credentials and every MARK already filed belong to a
     * DIFFERENT legal entity. In practice a new ΑΦΜ or ΓΕΜΗ means a new company,
     * not an edit. Used to warn the operator rather than to block them.
     *
     * Counts marks, not documents: a MARK is the proof a filing happened at all,
     * and it survives the document being cancelled (a cancellation is itself a
     * filing under the current identity).
     *
     * ONLY rows carrying a real MARK. `mydata_marks` also stores forensic rows with
     * a null mark — DRY_RUN, REJECTED, PROVIDER_FAILED — and counting those would
     * make a `mydata:test-submit` dry-run, or an AADE rejection, raise the warning
     * during exactly the pre-first-filing phase it must stay silent in. Worse, it
     * would warn against fixing the ΑΦΜ typo that caused the rejection.
     */
    public function filedDocumentCount(): int
    {
        // withoutGlobalScope, DECLARING the intent (CLAUDE.md rule (c)): this asks
        // about a NAMED company, not the ambient one. Both mark models carry
        // CompanyScope, and CompanyResource is panel-global — so a super_admin
        // editing any company other than the currently selected tenant would get 0
        // and the warning would silently never render. The explicit company_id
        // below is what scopes this; the ambient context must not narrow it further.
        // (EditCompany::afterSave() documents the same hazard.)
        $filed = fn (string $model): int => $model::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->getKey())
            ->whereNotNull('mark')
            ->where('mark', '!=', '')
            ->count();

        return $filed(MyDataMark::class) + $filed(DeliveryMark::class);
    }
}
