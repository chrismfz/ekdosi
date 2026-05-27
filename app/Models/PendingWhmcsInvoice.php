<?php

namespace App\Models;

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
 */
class PendingWhmcsInvoice extends Model
{
    use HasFactory;

    /**
     * Status lifecycle constants. Strings, not a real enum class, so
     * the inbox UI / future Stage B PRs can extend the set without
     * a code-side migration. New states should be additive only.
     */
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_FILED          = 'filed';
    public const STATUS_REJECTED       = 'rejected';
    public const STATUS_HELD           = 'held';

    /**
     * Match-reason constants - mirror MatchResult::$reason values so
     * downstream consumers can pattern-match without typo risk.
     */
    public const REASON_LINKED    = 'linked';
    public const REASON_AFM       = 'afm';
    public const REASON_EMAIL     = 'email';
    public const REASON_NAME      = 'name';
    public const REASON_UNMATCHED = 'unmatched';

    protected $fillable = [
        'company_id',
        'whmcs_invoice_id',
        'whmcs_userid',
        'customer_id',
        'payload',
        'match_reason',
        'status',
        'notes',
        'rejected_reason',
        'filed_at',
        'filed_by_user_id',
        'mydata_mark',
    ];

    protected function casts(): array
    {
        return [
            'payload'           => 'array',
            'filed_at'          => 'datetime',
            'whmcs_invoice_id'  => 'integer',
            'whmcs_userid'      => 'integer',
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
     * Once a row is filed, the payload becomes audit-frozen: a later
     * re-push from WHMCS (operator edited the invoice on the WHMCS
     * side, plugin re-fires the webhook) must NOT overwrite what we
     * filed against. The ingestor reads this to decide whether to
     * refresh the payload column.
     */
    public function isAuditFrozen(): bool
    {
        return $this->status === self::STATUS_FILED;
    }
}
