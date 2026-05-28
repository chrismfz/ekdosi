<?php

namespace App\Models;

use App\Models\Observers\PendingWhmcsInvoiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = [
        'company_id',
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
        'filed_at',
        'filed_by_user_id',
        'mydata_mark',
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
        ];
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
