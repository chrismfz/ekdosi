<?php

namespace App\Models;

use App\Enums\MyDataMode;
use App\Observers\CompanyObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'country_code',
        'einvoice_provider',
        'afm',
        'tax_office',
        'kad_primary',
        'address',
        'city',
        'postcode',
        'phone',
        'email',
        'mydata_aade_id_sandbox',
        'mydata_subscription_key_sandbox',
        'mydata_aade_id_production',
        'mydata_subscription_key_production',
        'mydata_mode',
        'gsis_username',
        'gsis_password',
        // PR #27: branding + outbound mail config
        'logo_path',
        'pdf_footer_text',
        'mail_from_address',
        'mail_from_name',
        'invoice_audit_bcc',
        'auto_email_on_mydata_accept',
        'auto_email_on_issue',
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
        // G8 phase 2: γκρινιάρης auto-issue knob + its default invoice type
        'whmcs_auto_issue_immediate',
        'whmcs_default_invoice_type_id',
    ];

    protected function casts(): array
    {
        return [
            'mydata_subscription_key_sandbox' => 'encrypted',
            'mydata_subscription_key_production' => 'encrypted',
            'gsis_password' => 'encrypted',
            'mail_smtp_password' => 'encrypted',
            'whmcs_api_secret' => 'encrypted',
            'whmcs_webhook_secret' => 'encrypted',
            'whmcs_custom_field_map' => 'array',
            'whmcs_invoice_min_date' => 'date',
            'whmcs_third_party_enabled' => 'boolean',
            'whmcs_amount_includes_tax' => 'boolean',
            'whmcs_fetch_via_bridge' => 'boolean',
            'whmcs_auto_issue_immediate' => 'boolean',
            'auto_email_on_mydata_accept' => 'boolean',
            'auto_email_on_issue' => 'boolean',
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

    /**
     * Operators with access to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * G8 phase 2: the invoice type the γκρινιάρης auto-issue uses. Null =
     * not configured → auto-issue skips this tenant (never guesses).
     */
    public function defaultWhmcsInvoiceType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class, 'whmcs_default_invoice_type_id');
    }
}
