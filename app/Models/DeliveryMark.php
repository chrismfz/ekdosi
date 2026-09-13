<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\MirrorsMovableFromDeliveryNote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legal audit row of one myDATA call for a delivery note — twin of MyDataMark.
 * Covers the issue INSERT and every e-transport lifecycle event
 * (REGISTER_TRANSFER / CONFIRM_OUTCOME / REJECT / CANCEL). Full request +
 * response XML preserved verbatim — DO NOT truncate/normalise.
 */
class DeliveryMark extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use MirrorsMovableFromDeliveryNote;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'delivery_note_id',
        // Polymorphic parent (Combined ΤΔΑ, 3a) — a DeliveryNote OR a ΤΔΑ Invoice.
        // Mirrored from delivery_note_id on write; see MirrorsMovableFromDeliveryNote.
        'movable_type',
        'movable_id',
        'mark',
        // AADE's own MARK for the cancellation ACT — distinct evidence from the
        // MARK of the document being cancelled (MYD-023).
        'cancellation_mark',
        'mydata_action',
        'provider_key',
        'authentication_code',
        'provider_delivery_state',
        'invoice_url',
        'request',
        'response',
        'mark_date',
        'mark_time',
    ];

    protected function casts(): array
    {
        return [
            'mark_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /** Greek label for the submission action (falls back to the raw value). */
    public function actionLabel(): string
    {
        return match ($this->mydata_action) {
            'INSERT' => 'Καταχώρηση',
            'PROVIDER_INSERT' => 'Καταχώρηση (πάροχος)',
            'REGISTER_TRANSFER' => 'Έναρξη διακίνησης',
            'CONFIRM_OUTCOME' => 'Δήλωση παράδοσης',
            'CONFIRM_RETURN' => 'Δήλωση επιστροφής',
            'CANCEL' => 'Ακύρωση',
            // MYD-019: a terminal AADE cancellation detected & synced via
            // «Έλεγχος κατάστασης» — distinct from a CANCEL we initiated.
            'STATE_SYNC' => 'Συγχρονισμός κατάστασης (ΑΑΔΕ)',
            'REJECTED' => 'Απόρριψη ΑΑΔΕ',
            'PROVIDER_REJECTED' => 'Απόρριψη (πάροχος)',
            'PROVIDER_FAILED' => 'Αποτυχία (πάροχος)',
            default => (string) $this->mydata_action,
        };
    }
}
