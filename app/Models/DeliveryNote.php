<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Παραστατικό Διακίνησης (Δελτίο Αποστολής). Value-less — carries no money/VAT,
 * so it never enters InvoiceScope or the money services. Issued via the same
 * SendInvoices path as invoices (a 9.x type); the delivery lifecycle layer
 * (RegisterTransfer/ConfirmDeliveryOutcome/…) drives `delivery_state` + the
 * `*_mark` columns.
 *
 * The myDATA cache (`mydata_*`) + the lifecycle marks are NOT fillable — written
 * ONLY by the submitter / lifecycle service via forceFill, alongside their
 * `delivery_marks` audit row. Same guard as Invoice's mydata cache.
 */
class DeliveryNote extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'invcode',
        'code',
        'delivery_type_id',
        'customer_id',
        'issued_at',
        'mydata_type',
        'move_purpose',
        'other_move_purpose_title',
        'distribution_aim_id',
        'delivery_method_id',
        'dispatch_at',
        'vehicle_number',
        'transport_type',
        'carrier_afm',
        'third_party_collection',
        'loading_street',
        'loading_number',
        'loading_postcode',
        'loading_city',
        'start_shipping_branch',
        'delivery_street',
        'delivery_number',
        'delivery_postcode',
        'delivery_city',
        'complete_shipping_branch',
        'recipient_name',
        'recipient_afm',
        'local_status',
        'printed',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'dispatch_at' => 'datetime',
            'move_purpose' => 'integer',
            'transport_type' => 'integer',
            'start_shipping_branch' => 'integer',
            'complete_shipping_branch' => 'integer',
            'third_party_collection' => 'boolean',
            'printed' => 'boolean',
            'mydata_sent' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The InvoiceType row (a 9.x type) that drives series + numbering. */
    public function deliveryType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class, 'delivery_type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function distributionAim(): BelongsTo
    {
        return $this->belongsTo(DistributionAim::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(DeliveryMark::class);
    }

    public function latestMark(): HasOne
    {
        return $this->hasOne(DeliveryMark::class)->latestOfMany();
    }
}
