<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\MirrorsMovableFromDeliveryNote;
use App\Support\MyData\DeliveryCodes;
use Firebed\AadeMyData\Enums\DigitalGoodsMovement\DeliveryEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AADE-reported lifecycle event of a δελτίο (§4.1) — the carrier/recipient
 * timeline surfaced by GetDeliveryNoteStatus. Read-only from the operator's
 * side: rows are written ONLY by DeliveryLifecycleService::syncLifecycleHistory
 * (idempotent on `dedup_key`). The `details` JSON carries the populated one of
 * transport/outcome/rejection specifics; `summary()` renders it in Greek.
 */
class DeliveryNoteEvent extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use MirrorsMovableFromDeliveryNote;

    protected $fillable = [
        'company_id',
        'delivery_note_id',
        // Polymorphic parent (Combined ΤΔΑ, 3a) — mirrored from delivery_note_id
        // on write; see MirrorsMovableFromDeliveryNote.
        'movable_type',
        'movable_id',
        'event_mark',
        'event_type',
        'event_timestamp',
        'actor_vat',
        'details',
        'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'event_timestamp' => 'datetime',
            'details' => 'array',
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

    /** Greek label for the §7.2 event type (falls back to the raw value). */
    public function typeLabel(): string
    {
        return DeliveryEventType::tryFrom((string) $this->event_type)?->label()
            ?? (string) $this->event_type;
    }

    /** Human one-line summary of the event's transport/outcome/rejection block. */
    public function summary(): string
    {
        $d = $this->details ?? [];

        return match ($this->event_type) {
            // RegisterTransferReturn (v2.0.2) carries the same transportDetails block
            // as RegisterTransfer — the carrier-reported return leg — so it renders
            // identically (vehicle / type / carrier).
            DeliveryEventType::REGISTER_TRANSFER->value,
            DeliveryEventType::REGISTER_TRANSFER_RETURN->value => trim(implode(' · ', array_filter([
                DeliveryCodes::transportTypeLabel($d['transport_type'] ?? null),
                isset($d['vehicle_number']) ? 'Όχημα '.$d['vehicle_number'] : null,
                isset($d['carrier_vat']) ? 'Μεταφορέας '.$d['carrier_vat'] : null,
            ]))),
            DeliveryEventType::CONFIRM_OUTCOME->value => trim(implode(' · ', array_filter([
                DeliveryCodes::outcomeLabel($d['outcome'] ?? null),
                ($d['delivered_without_recipient'] ?? false) ? 'χωρίς παρουσία παραλήπτη' : null,
            ]))),
            DeliveryEventType::REJECTION->value => $d['reason'] ?? 'Απόρριψη',
            // ConfirmReturn (v2.0.2) carries no detail block — the type label
            // «Επιβεβαίωση επιστροφής» (typeLabel) already says everything.
            DeliveryEventType::CONFIRM_RETURN->value => '',
            default => '',
        };
    }
}
