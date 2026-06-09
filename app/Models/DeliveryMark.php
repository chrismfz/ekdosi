<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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

    protected $fillable = [
        'company_id',
        'legacy_id',
        'delivery_note_id',
        'mark',
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
            'CANCEL' => 'Ακύρωση',
            default => (string) $this->mydata_action,
        };
    }
}
