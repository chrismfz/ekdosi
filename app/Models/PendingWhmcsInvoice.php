<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Observers\PendingWhmcsInvoiceObserver;
use App\Support\Afm;
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
        'whmcs_payment_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'third_party_resolution' => 'array',
            'filed_at' => 'datetime',
            'whmcs_payment_pushed_at' => 'datetime',
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

    /**
     * Does the WHMCS «γκρινιάρης» (immediate-invoice) custom field mark this client
     * for άμεση τιμολόγηση? Reads the mapped 'griniaris' role checkbox (same truthy
     * set — and same null-when-unmapped contract — as wantsInvoice):
     *   - null  = the tenant hasn't MAPPED the griniaris field → intent unknown, so
     *             WHMCS is NOT the source of truth here (leave the flag to the operator).
     *   - true  = the field is mapped AND checked.
     *   - false = the field is mapped but absent/unchecked.
     *
     * WHMCS is the source of truth for this flag on WHMCS-linked customers: it seeds
     * customers.needs_immediate_invoice at CREATE (WhmcsCustomerCreator) and is MIRRORED
     * onto the matched customer on every ingest (WhmcsInvoiceIngestor) — so a later
     * WHMCS toggle propagates. The null (unmapped) case is exactly what stops a tenant
     * that doesn't use the field from having every customer's flag forced off.
     */
    public function wantsImmediateInvoice(): ?bool
    {
        if ($this->company?->whmcsCustomFieldId('griniaris') === null) {
            return null;
        }
        $v = mb_strtolower((string) $this->whmcsCustomField('griniaris'));

        return in_array($v, ['on', '1', 'yes', 'true', 'ναι', 'checked'], true);
    }

    /**
     * Receipt-vs-invoice for the customer's OWN (non-routed) lines: true =
     * «Απόδειξη», false = «Τιμολόγιο». Driven by the PRIMARY customer, NOT the
     * per-route is_receipt (which the plugin defaults to false for own lines —
     * the legacy «own portion always τιμολόγιο» bug). Rule:
     *   - no ekdosi customer ΑΦΜ → Απόδειξη — base it on customer.afm (the ΑΦΜ
     *     that actually goes ON the document), NOT a WHMCS-typed vatno that never
     *     made it onto the record: typing such a row as a τιμολόγιο would file an
     *     invoice with an empty counterpart ΑΦΜ (tax-invalid / AADE-rejected);
     *   - has ΑΦΜ + explicit wantsinvoice=false → Απόδειξη (business buying retail);
     *   - has ΑΦΜ otherwise (wants invoice / unknown) → Τιμολόγιο.
     * The «wants invoice but no ΑΦΜ» contradiction is a HOLD, not a silent receipt
     * — the auto-issue + inbox flows guard it separately (a τιμολόγιο needs the
     * operator to fill the ΑΦΜ first).
     */
    public function ownLinesAreReceipt(): bool
    {
        // Identity, not text: a placeholder («000000000») on the linked
        // customer is no ΑΦΜ either — same rule as whmcsAfm().
        if (Afm::uniqueKey($this->customer?->afm) === null) {
            return true;
        }

        return $this->wantsInvoice() === false;
    }

    /**
     * The WHMCS invoice's payment status as WHMCS reports it ('Paid' / 'Unpaid'
     * / 'Cancelled' / 'Refunded' / …), read straight from the stored payload.
     * null when the payload carries no status (older snapshots / partial fetch).
     */
    public function whmcsStatus(): ?string
    {
        $status = $this->payload['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Is the source WHMCS invoice UNPAID? True only for the explicit 'Unpaid'
     * status (the «θέλει πρώτα τιμολόγιο, μετά πληρώνει» public-sector / Α.Ε.
     * case). Paid/Cancelled/Refunded/unknown → false (those are either settled
     * or shouldn't be issued anyway).
     */
    public function whmcsIsUnpaid(): bool
    {
        return strcasecmp((string) $this->whmcsStatus(), 'Unpaid') === 0;
    }

    /**
     * The invoice type the «Δημιουργία Παραστατικού» draft should PRE-SELECT for
     * this row — a starting point the operator always overrides. Status- and
     * intent-aware:
     *   - UNPAID WHMCS invoice → the tenant's «επί πιστώσει» default
     *     (`whmcs_default_unpaid_type_id`), so the issued invoice stays an OPEN
     *     receivable; falls back to the paid invoice default if unset.
     *   - PAID + own lines are a receipt (no ΑΦΜ / wantsinvoice=false) → the
     *     receipt default (`whmcs_default_receipt_type_id`) when configured.
     *   - PAID otherwise → the paid invoice default (`whmcs_default_invoice_type_id`).
     * Never guesses a type the tenant hasn't configured (null → operator picks).
     */
    public function suggestedInvoiceTypeId(Company $tenant): ?int
    {
        if ($this->whmcsIsUnpaid()) {
            return $tenant->whmcs_default_unpaid_type_id
                ?? $tenant->whmcs_default_invoice_type_id;
        }

        if ($this->ownLinesAreReceipt() && $tenant->whmcs_default_receipt_type_id !== null) {
            return $tenant->whmcs_default_receipt_type_id;
        }

        return $tenant->whmcs_default_invoice_type_id;
    }

    /**
     * For a SINGLE third-party row, the uniform receipt-vs-invoice intent of its
     * route(s): true = «Απόδειξη», false = «Τιμολόγιο», null = no routes OR mixed
     * flags (ambiguous → must go to the operator, never auto-typed). The flag is
     * the explicit per-route `is_receipt` set in the WHMCS plugin's client area —
     * decided by the THIRD PARTY's nature, independent of the WHMCS client's VAT.
     */
    public function singleThirdPartyReceipt(): ?bool
    {
        $lines = $this->third_party_resolution['lines'] ?? [];
        if ($lines === []) {
            return null;
        }
        $flags = array_unique(array_map(
            static fn ($l): bool => (bool) ($l['is_receipt'] ?? false),
            $lines
        ));

        return count($flags) === 1 ? (bool) reset($flags) : null;
    }

    /**
     * The ΑΦΜ the customer entered in WHMCS (role 'vatno') as its IDENTITY
     * (Afm::uniqueKey): digits for a Greek ΑΦΜ with any EL/GR prefix dropped,
     * letters kept for a foreign VAT, null for a blank or a placeholder.
     */
    public function whmcsAfm(): ?string
    {
        return Afm::uniqueKey($this->whmcsCustomField('vatno'));
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

        $hasAfm = Afm::uniqueKey($this->customer?->afm) !== null || filled($this->whmcsAfm());

        return ! $hasAfm;
    }

    /**
     * WHMCS "mass payment" / consolidated-invoice detection.
     *
     * When a client pays several open invoices at once, WHMCS generates a NEW
     * invoice whose every line item is a REFERENCE to another invoice
     * (tblinvoiceitems.type='Invoice', relid=<source invoice id>; line text
     * "Invoice #31690" / localised "Αρ. Λογαριασμού #31690"). Such an invoice
     * carries NO VAT of its own — the tax was already charged on the source
     * invoices — so WHMCS reports it at 0% and its line amounts are the source
     * invoices' GROSS totals.
     *
     * It is a payment-grouping artefact, NOT a sale. Issuing it would (a)
     * double-count the source invoices (which are themselves staged in this
     * inbox) and (b) file their gross at 0% ΦΠΑ — wrong, and with άμεση
     * τιμολόγηση ON it would even auto-file to AADE. So we detect it and refuse
     * to issue it; the source invoices are the real παραστατικά.
     *
     * Returns null when this is NOT a consolidated payment; otherwise the
     * referenced WHMCS invoice ids (possibly empty if the ids can't be parsed
     * but the shape is unmistakably mass-pay). Conservative: EVERY non-empty
     * line must be an invoice reference, so a normal invoice that merely
     * contains one stray reference line is never blocked wholesale.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, int>|null
     */
    public static function detectConsolidatedRefs(array $payload): ?array
    {
        $items = $payload['items']['item'] ?? null;
        if (! is_array($items) || $items === []) {
            return null;
        }
        if (! array_is_list($items)) {
            $items = [$items];   // WHMCS returns a bare object for a single line
        }

        // WHMCS pads invoices with empty-description rows — keep only real lines.
        $lines = [];
        foreach ($items as $item) {
            if (is_array($item) && trim((string) ($item['description'] ?? '')) !== '') {
                $lines[] = $item;
            }
        }
        if ($lines === []) {
            return null;
        }

        // Prefer the authoritative per-line `type` when the payload carries it
        // (native GetInvoice). Only fall back to the description/taxed heuristic
        // when NO line has a type at all (e.g. a slimmed bridge feed).
        $hasTypeInfo = false;
        foreach ($lines as $l) {
            if (trim((string) ($l['type'] ?? '')) !== '') {
                $hasTypeInfo = true;
                break;
            }
        }

        $refs = [];
        foreach ($lines as $l) {
            $ref = $hasTypeInfo
                ? self::massPayRefByType($l)
                : self::massPayRefByText($l);
            if ($ref === null) {
                return null;   // a real (non-reference) line → not a pure mass-pay
            }
            $refs[] = $ref;
        }

        return array_values(array_unique(array_filter($refs, static fn (int $r): bool => $r > 0)));
    }

    public function isConsolidatedPayment(): bool
    {
        return self::detectConsolidatedRefs($this->payload ?? []) !== null;
    }

    /** @return array<int, int> the source WHMCS invoice ids ([] if not consolidated / unparseable). */
    public function consolidatedPaymentRefs(): array
    {
        return self::detectConsolidatedRefs($this->payload ?? []) ?? [];
    }

    /**
     * Operator-facing reason a consolidated WHMCS payment is parked (held)
     * instead of issued — lists the source invoices so the operator knows WHICH
     * παραστατικά to file instead. Shared by the ingestor's hold, auto-issue's
     * skip, and the inbox draft guard so the wording can't drift.
     *
     * @param  array<int, int>  $refs
     */
    public static function consolidatedPaymentReason(array $refs): string
    {
        $list = $refs !== []
            ? ' Συγκεντρώνει τα WHMCS #'.implode(', #', $refs).'.'
            : '';

        return 'Συγκεντρωτικό τιμολόγιο πληρωμής WHMCS (mass-pay) — δεν αντιστοιχεί σε πώληση και δεν έχει δικό του ΦΠΑ.'
            .$list.' Έκδοσε τα επιμέρους παραστατικά (έρχονται ξεχωριστά στο inbox), όχι αυτό.';
    }

    /**
     * A line is a mass-pay reference when WHMCS typed it `Invoice` — the marker
     * it puts on every line of a consolidated payment — carrying relid=<source
     * invoice id>. Returns the source id, 0 when typed Invoice but the id is
     * missing, or null for an ordinary product/service line.
     *
     * @param  array<string, mixed>  $line
     */
    private static function massPayRefByType(array $line): ?int
    {
        if (strtolower(trim((string) ($line['type'] ?? ''))) !== 'invoice') {
            return null;
        }
        $relid = (int) ($line['relid'] ?? 0);

        return $relid > 0 ? $relid : (self::invoiceRefFromText((string) ($line['description'] ?? '')) ?? 0);
    }

    /**
     * Heuristic fallback for payloads with no per-line type: a reference line is
     * untaxed AND its text reads "Invoice #N" / "Αρ. Λογαριασμού #N". A taxed
     * line or unrecognised text → null (treated as a real sale, so a genuine
     * 0%/exempt invoice is never mistaken for a mass-pay).
     *
     * @param  array<string, mixed>  $line
     */
    private static function massPayRefByText(array $line): ?int
    {
        if ((int) ($line['taxed'] ?? 1) !== 0) {
            return null;
        }

        return self::invoiceRefFromText((string) ($line['description'] ?? ''));
    }

    /**
     * Parse the source invoice id out of a WHMCS mass-pay line description.
     * Requires an invoice-ish keyword (EN «invoice» / GR «τιμολ» / «λογαρ») AND
     * a #<number> so a product description that merely contains a number can't
     * false-match. Returns null when the text isn't an invoice reference.
     */
    private static function invoiceRefFromText(string $desc): ?int
    {
        if (! preg_match('/invoice|τιμολ|λογαρ/iu', $desc)) {
            return null;
        }
        if (preg_match('/#\s*(\d+)/', $desc, $m)) {
            return (int) $m[1];
        }

        return null;
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
