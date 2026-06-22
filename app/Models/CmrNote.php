<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CMR — international road consignment note. A self-contained TRANSPORT document
 * (no myDATA/AADE, no VAT): see docs/cmr-international-delivery.md. It can stand
 * alone or reference one of our documents (DeliveryNote | Invoice) via the
 * optional polymorphic `source`; either way it owns its own goods lines so a
 * standalone CMR works and a sourced one is freely editable (English).
 *
 * `number` is a simple per-company counter (archival), allocated on create — NOT
 * a fiscal ΑΑ. Stays a `draft` (editable) until printed/finalized; printing does
 * not lock it (a transport doc, not a legal παραστατικό).
 */
class CmrNote extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'company_id', 'number', 'reference_no', 'status',
        'source_type', 'source_id', 'customer_id', 'issued_at',
        'sender_text', 'consignee_text', 'delivery_text', 'taking_over_place', 'taking_over_at',
        'carrier_name', 'carrier_address', 'successive_carrier', 'carrier_reservations',
        'tractor_plate', 'trailer_plate',
        'annexed_documents', 'sender_instructions', 'special_agreements',
        'freight_paid', 'charges_to_be_paid_by',
        'carriage_charges', 'reductions', 'balance', 'supplement', 'misc_charges',
        'total_charges', 'cash_on_delivery',
        'established_place', 'established_on', 'copies_count', 'printed', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'taking_over_at' => 'datetime',
            'established_on' => 'date',
            'freight_paid' => 'boolean',
            'printed' => 'boolean',
            'copies_count' => 'integer',
            'number' => 'integer',
            'carriage_charges' => 'decimal:2',
            'reductions' => 'decimal:2',
            'balance' => 'decimal:2',
            'supplement' => 'decimal:2',
            'misc_charges' => 'decimal:2',
            'total_charges' => 'decimal:2',
            'cash_on_delivery' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Allocate the per-company counter on create (simple max+1 — CMR is not a
        // fiscal document, so no strict gap-free ΑΑ sequence is required). Default
        // issued_at + reference_no when the caller didn't set them.
        static::creating(function (CmrNote $cmr): void {
            if (empty($cmr->number) && $cmr->company_id) {
                // lockForUpdate gap-locks this company's range on the
                // unique(company_id, number) index, serialising concurrent
                // creates so two don't grab the same number (→ duplicate-key
                // crash). Requires an enclosing transaction: the service path
                // wraps one, and the Filament Create/Edit pages enable
                // hasDatabaseTransactions. Non-fiscal counter, so no gap-free
                // ΑΑ guarantee is needed — just no collision.
                $max = static::withoutGlobalScopes()
                    ->where('company_id', $cmr->company_id)
                    ->lockForUpdate()
                    ->max('number');
                $cmr->number = (int) $max + 1;
            }
            if (empty($cmr->issued_at)) {
                $cmr->issued_at = now();
            }
            if (blank($cmr->reference_no)) {
                $cmr->reference_no = 'CMR-'.$cmr->number;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CmrLine::class);
    }

    public function code(): string
    {
        return (string) ($this->reference_no ?: 'CMR-'.$this->number);
    }

    /**
     * Mark the CMR as printed (and finalize it if still a draft). Shared by the
     * print actions so the list and the edit page behave identically. A CMR isn't
     * fiscal, so this is just a marker — the record stays editable/re-printable.
     */
    public function markPrinted(): void
    {
        $this->forceFill([
            'printed' => true,
            'status' => $this->status === self::STATUS_DRAFT ? self::STATUS_FINALIZED : $this->status,
        ])->save();
    }
}
