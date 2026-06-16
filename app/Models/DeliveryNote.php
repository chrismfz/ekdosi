<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\TracksActivity;
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
    use HasAttachments;
    use HasFactory;
    use HasInternalNotes;
    use SoftDeletes;
    use TracksActivity;

    /**
     * Business columns worth auditing — never the money-less doc's churn. We log
     * mydata_state/mydata_mark (set once on issue/cancel — meaningful events, as
     * on Invoice) but NOT delivery_state: that column is re-forceFilled on every
     * AADE status poll (DeliveryLifecycleService::refreshStatus), so logging it
     * would spam «Ιστορικό» with sync flips — exactly the cache-column churn the
     * TracksActivity house rule excludes. Each lifecycle transition is already
     * captured as its own DeliveryMark row.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'code', 'customer_id', 'delivery_type_id', 'invoice_id', 'issued_at',
            'move_purpose', 'local_status', 'mydata_state', 'mydata_mark',
        ];
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'invcode',
        'code',
        'delivery_type_id',
        'customer_id',
        'invoice_id',
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

    /** Optional link to the sale (invoice) this δελτίο dispatches — for stock dedup. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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

    /**
     * AADE-reported lifecycle history (§4.1) — the carrier/recipient timeline,
     * synced by DeliveryLifecycleService::syncLifecycleHistory on refreshStatus.
     * Ordered oldest→newest so the View reads as a timeline.
     */
    public function events(): HasMany
    {
        return $this->hasMany(DeliveryNoteEvent::class)->orderBy('event_timestamp');
    }

    public function latestMark(): HasOne
    {
        return $this->hasOne(DeliveryMark::class)->latestOfMany();
    }

    /**
     * The myDATA «Outbox» for delivery notes: δελτία that should be registered to
     * AADE but carry no MARK yet (draft awaiting issue, or a registration that
     * never landed). Predicate: a filable type (`mydata_type` set), not cancelled,
     * no `mydata_mark`. Mirrors Invoice::scopeAwaitingMyData.
     */
    public function scopeAwaitingMyData(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereNotNull('mydata_type')
            ->where('local_status', '!=', 'cancelled')
            ->whereNull('mydata_mark');
    }
}
