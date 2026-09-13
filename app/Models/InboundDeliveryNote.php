<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\MyData\DeliveryCodes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Slice 4 — a ψηφιακή-διακίνηση document OTHERS filed against us (goods we are
 * RECEIVING), staged in «Εισερχόμενα Διακίνησης» for an operator to reject /
 * refresh / (later) confirm. See docs/delivery-inbound-design.md.
 *
 * The recipient twin of the issuer `DeliveryNote`/ΤΔΑ flow — but these are OTHER
 * parties' legal documents (we hold no issued MARK for them), so they live in
 * this dedicated staging table (like `pending_whmcs_invoices`), NOT the issuer
 * `delivery_marks`/`delivery_note_events` polymorphic audit.
 *
 * Tenant safety: `BelongsToCompany` adds the ambient `CompanyScope` for the
 * panel; the fetch command/service run OUTSIDE panel context and scope EVERY
 * query explicitly by `->where('company_id', …)` (never a global-scope reliance),
 * per the CLAUDE.md CLI/queue rule.
 *
 * `local_state` (OUR disposition) is deliberately orthogonal to
 * `aade_delivery_status` (the AADE truth), the same two-status discipline as
 * invoices.local_status × mydata_state: a refresh updates the AADE status, an
 * operator action updates our state.
 */
class InboundDeliveryNote extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    /** OUR disposition. Strings (not an enum class) so the set stays additive. */
    public const STATE_NEW = 'new';

    public const STATE_ACKNOWLEDGED = 'acknowledged';

    public const STATE_REJECTED = 'rejected';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_CANCELLED_BY_ISSUER = 'cancelled_by_issuer';

    /** Terminal states — no further recipient action is meaningful. */
    public const TERMINAL_STATES = [
        self::STATE_REJECTED,
        self::STATE_CONFIRMED,
        self::STATE_CANCELLED_BY_ISSUER,
    ];

    /** Greek labels for `local_state` (operator-facing). */
    public const STATE_LABELS = [
        self::STATE_NEW => 'Νέο',
        self::STATE_ACKNOWLEDGED => 'Παραλήφθηκε',
        self::STATE_REJECTED => 'Απορρίφθηκε',
        self::STATE_CONFIRMED => 'Επιβεβαιώθηκε',
        self::STATE_CANCELLED_BY_ISSUER => 'Ακυρώθηκε από τον εκδότη',
    ];

    public static function stateLabel(?string $state): ?string
    {
        return $state === null ? null : (self::STATE_LABELS[$state] ?? $state);
    }

    protected $fillable = [
        'company_id',
        'mydata_mark',
        'issuer_afm',
        'issuer_name',
        'supplier_id',
        'invoice_type',
        'aa',
        'issue_date',
        'qr_code_url',
        'aade_delivery_status',
        'local_state',
        'reject_mark',
        'outcome_mark',
        'payload',
        'lifecycle',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'aade_delivery_status' => 'integer',
            'payload' => 'array',
            'lifecycle' => 'array',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Greek label for the current AADE delivery status (§7.1), or null when the
     * status is unknown/unset (the caller shows «—»).
     */
    public function aadeStatusLabel(): ?string
    {
        return DeliveryCodes::deliveryStatusLabel($this->aade_delivery_status);
    }

    /** Is this row already in a terminal disposition on our side? */
    public function isTerminal(): bool
    {
        return in_array($this->local_state, self::TERMINAL_STATES, true);
    }
}
