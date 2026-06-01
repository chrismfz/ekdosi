<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use App\Models\Observers\PendingWhmcsInvoiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PR #31 (WHMCS bridge - Stage B-1): a WHMCS invoice awaiting operator
 * review before being filed at AADE.
 *
 * Rows land here via either:
 *   - WhmcsInvoiceIngestor (called by the artisan pull command or the
 *     /webhooks/whmcs/{slug}/invoice-paid HTTP endpoint).
 *
 * Rows are promoted out of this table (status -> filed) only by the
 * Stage B-2 inbox UI (not yet built). Nothing in Stage B-1 talks to
 * AADE.
 *
 * NOT using Filament's BelongsToTenant trait here, deliberately:
 * the ingest paths (artisan command, webhook controller) run outside
 * panel context, where BelongsToTenant silently returns "no rows".
 * Every query in this PR scopes by `->where('company_id', $tenant->id)`
 * explicitly. When the Stage B-2 inbox lands, IT can add BelongsToTenant
 * on top of the explicit scoping (defence in depth) - the explicit
 * filter still runs first.
 *
 * NOT soft-deletes: a "deleted" row would still occupy the
 * (company_id, whmcs_invoice_id) unique slot. If an operator wants
 * "make this go away" semantics, they reject it (status='rejected') -
 * the row stays for audit. Force-delete is the breakglass.
 *
 * Observer: PendingWhmcsInvoiceObserver enforces the legal-audit lock
 * against mutating status=filed rows from any caller (defense in depth
 * beyond the ingestor's policy-level isAuditFrozen() check).
 */
#[ObservedBy(PendingWhmcsInvoiceObserver::class)]
class PendingWhmcsInvoice extends Model
{
    use BelongsToCompany;

    use HasFactory;

    /**
     * Status lifecycle constants. Strings, not a real enum class, so
     * the inbox UI / future Stage B PRs can extend the set without
     * a code-side migration. New states should be additive only.
     */
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_FILED = 'filed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_HELD = 'held';

    // T-1c: split into N per-party draft invoices (multi-party third-party
    // invoicing). The drafts carry invoices.whmcs_pending_id back-references;
    // the operator files each via the normal myDATA submit path.
    public const STATUS_SPLIT = 'split';

    // Draft-first single-party flow: the inbox creates an editable DRAFT
    // invoice (no AADE submit) so the operator reviews/fixes line text before
    // issuing it through the normal invoice lifecycle. Safer than filing
    // straight from the inbox.
    public const STATUS_DRAFTED = 'drafted';

    /**
     * Match-reason constants - mirror MatchResult::$reason values so
     * downstream consumers can pattern-match without typo risk.
     */
    public const REASON_LINKED = 'linked';

    public const REASON_AFM = 'afm';

    public const REASON_EMAIL = 'email';

    public const REASON_NAME = 'name';

    public const REASON_UNMATCHED = 'unmatched';

    /**
     * Stage B-3: WHMCS write-back lifecycle. `null` means N/A
     * (off-mode tenant — no MARK to push). See the
     * add_writeback_state migration docblock for the full semantics.
     */
    public const WRITEBACK_PENDING = 'pending';

    public const WRITEBACK_SUCCEEDED = 'succeeded';

    public const WRITEBACK_FAILED = 'failed';

    public const WRITEBACK_SKIPPED = 'skipped';

    /**
     * T-1b: third-party-invoicing resolution state. `null` = not evaluated
     * (kill-switch off, or resolution unavailable). Set by the ingestor from
     * the bridge's resolve.php.
     */
    public const TP_NONE = 'none';    // resolved, every line bills the WHMCS client

    public const TP_SINGLE = 'single';  // whole invoice → one third-party contact

    public const TP_MULTI = 'multi';   // mixes billing parties → held for operator split (T-1c)

    // Phase 0 (Bridges/Connectors): which billing source this staged doc came
    // from. Only 'whmcs' today; the column lets a future WooCommerce/Blesta
    // source share this inbox (or drive per-source inboxes). See
    // docs/bridges-connectors.md.
    public const SOURCE_WHMCS = 'whmcs';

    protected $fillable = [
        'company_id',
        'source',
        'whmcs_invoice_id',
        'whmcs_userid',
        'customer_id',
        'invoice_id',
        'payload',
        'match_reason',
        'third_party_state',
        'third_party_resolution',
        'status',
        'notes',
        'rejected_reason',
        'hold_reason',
        'filed_at',
        'filed_by_user_id',
        'mydata_mark',
        'legacy_invoiced',
        'whmcs_writeback_state',
        'whmcs_writeback_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'third_party_resolution' => 'array',
            'filed_at' => 'datetime',
            'whmcs_invoice_id' => 'integer',
            'whmcs_userid' => 'integer',
            'legacy_invoiced' => 'integer',
        ];
    }

    /**
     * Has this WHMCS invoice ALSO been invoiced in the LEGACY ekdosi app
     * (tblinvoices.invoiced != 0)? During the dual-run this warns the operator
     * not to issue an ekdosi παραστατικό for something the old app already
     * filed. null legacy_invoiced = unknown → returns false (no false alarm).
     */
    public function invoicedInLegacy(): bool
    {
        return $this->legacy_invoiced !== null && $this->legacy_invoiced !== 0;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function filedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
    }

    /**
     * The ekdosi Invoice this WHMCS row was filed against (set by
     * WhmcsInvoiceFiler atomically inside the filing transaction).
     * Null until the first filing attempt persists; remains set even
     * after a failed AADE submit so a retry can reuse the SAME
     * Invoice instead of allocating a new ΑΑ counter.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * T-1c: the per-party draft invoices produced by splitting a multi-party
     * WHMCS invoice (each carries invoices.whmcs_pending_id = this row). The
     * 1:1 `invoice()` link above stays null on the split path.
     */
    public function splitInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'whmcs_pending_id');
    }

    /**
     * Read a WHMCS client custom field off the staged payload by canonical
     * role (vatno / taxoffice / occupation / griniaris / wantsinvoice …),
     * resolving the role → WHMCS field-id via the tenant's
     * whmcs_custom_field_map. The payload is enriched with the client's
     * `customfields` block at ingest (WhmcsClient::getInvoiceWithClient), so
     * the billing-intent the customer set in WHMCS is readable here — no
     * extra API call. Returns null when unmapped or absent.
     */
    /**
     * Per-instance memo so a single table row (state + color + icon + tooltip
     * + needsAfm all read intent) parses the payload / resolves the field map
     * once, not 5-7×.
     *
     * @var array<string, ?string>
     */
    private array $whmcsFieldCache = [];

    public function whmcsCustomField(string $role): ?string
    {
        if (array_key_exists($role, $this->whmcsFieldCache)) {
            return $this->whmcsFieldCache[$role];
        }

        return $this->whmcsFieldCache[$role] = $this->resolveWhmcsCustomField($role);
    }

    private function resolveWhmcsCustomField(string $role): ?string
    {
        $fieldId = $this->company?->whmcsCustomFieldId($role);
        if ($fieldId === null) {
            return null;
        }

        $fields = $this->payload['customfields'] ?? [];
        if ($fields === [] || $fields === null) {
            return null;
        }
        // WHMCS returns a list of {id,name,value} — or a single such object
        // when there's exactly one field (same quirk the matcher handles).
        // NOTE (review follow-up): this lookup + the AFM normalisation below
        // duplicate WhmcsCustomerMatcher::extractCustomField/normaliseAfm — a
        // shared WHMCS-payload reader should fold the two together (deferred,
        // touches the matcher).
        if (! array_is_list($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $f) {
            if ((int) ($f['id'] ?? 0) === $fieldId) {
                $v = trim((string) ($f['value'] ?? ''));

                return $v === '' ? null : $v;
            }
        }

        return null;
    }

    /**
     * Did the customer ask for an invoice (τιμολόγιο) vs a receipt? Reads the
     * 'wantsinvoice' role checkbox. null = the tenant hasn't mapped the field
     * (intent unknown → operator decides).
     */
    public function wantsInvoice(): ?bool
    {
        if ($this->company?->whmcsCustomFieldId('wantsinvoice') === null) {
            return null;
        }
        $v = mb_strtolower((string) $this->whmcsCustomField('wantsinvoice'));

        return in_array($v, ['on', '1', 'yes', 'true', 'ναι', 'checked'], true);
    }

    /** The ΑΦΜ the customer entered in WHMCS (role 'vatno'), digits only. */
    public function whmcsAfm(): ?string
    {
        $raw = $this->whmcsCustomField('vatno');
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);

        return $digits === '' ? null : $digits;
    }

    public function whmcsTaxOffice(): ?string
    {
        return $this->whmcsCustomField('taxoffice');
    }

    public function whmcsActivity(): ?string
    {
        return $this->whmcsCustomField('occupation');
    }

    /**
     * The WHMCS client's display name off the staged payload — company name if
     * present, else firstname + lastname. Lets the inbox show "from WHMCS
     * client X" even when no ekdosi customer is linked yet.
     */
    public function whmcsClientName(): ?string
    {
        $p = $this->payload ?? [];

        $company = trim((string) ($p['companyname'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        $name = trim(trim((string) ($p['firstname'] ?? '')).' '.trim((string) ($p['lastname'] ?? '')));

        return $name !== '' ? $name : null;
    }

    /**
     * C: the customer wants a τιμολόγιο but no ΑΦΜ is available anywhere — not
     * on the matched ekdosi customer, not in WHMCS. You can't file a proper
     * invoice without it, so the inbox flags it (hold "Αναμονή για ΑΦΜ").
     * Only fires when the intent is KNOWN to be "invoice" (wantsInvoice true);
     * unknown intent doesn't raise a false alarm. Needs the customer relation
     * loaded (the inbox eager-loads it).
     */
    public function needsAfm(): bool
    {
        if ($this->wantsInvoice() !== true) {
            return false;
        }

        $hasAfm = filled($this->customer?->afm) || filled($this->whmcsAfm());

        return ! $hasAfm;
    }

    /**
     * Payload-refresh policy for re-ingests. Returns true for any
     * status EXCEPT pending_review. The rule:
     *
     *   pending_review  -> open: refresh payload + re-run matcher on
     *                     re-ingest (operator wants to see the latest
     *                     WHMCS-side state in their inbox).
     *   held            -> frozen: operator paused investigation; the
     *                     payload they were looking at must stay put
     *                     so when they lift the hold the data still
     *                     matches the reason they paused.
     *   rejected        -> frozen: operator's rejected_reason was
     *                     captured against the payload at decision
     *                     time; allowing WHMCS-side edits to mutate
     *                     it underneath would decouple the reason
     *                     from its referent.
     *   filed           -> frozen: legal-audit lock. The MARK at AADE
     *                     references this exact payload; any later
     *                     mutation diverges our DB from AADE's
     *                     authoritative record.
     *
     * Note this is the INGESTOR's refresh-or-not policy. The STRICT
     * legal-audit lock against ANY mutation (including from non-ingestor
     * callers like the future Stage B-2 inbox actions) is enforced
     * separately by PendingWhmcsInvoiceObserver on status=filed rows
     * only.
     */
    public function isAuditFrozen(): bool
    {
        return $this->status !== self::STATUS_PENDING_REVIEW;
    }
}
