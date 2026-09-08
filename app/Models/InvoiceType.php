<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceType extends Model
{
    use BelongsToCompany;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'invcount',
        'show_on_menu',
        'is_favorite',
        'is_credit',
        'is_return',
        'mydata_type',
        'mydata_income_class',
        'mydata_income_class_category',
        'mydata_requires_quantity',
        'distribution_aim_id',
        'delivery_method_id',
        'payment_method_id',
        'default_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'invcount' => 'integer',
            'show_on_menu' => 'boolean',
            'is_favorite' => 'boolean',
            'is_credit' => 'boolean',
            'is_return' => 'boolean',
            'mydata_requires_quantity' => 'boolean',
        ];
    }

    /**
     * Tenant relation — required by Filament's BelongsToTenant trait
     * when InvoiceTypeResource lands.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function defaultCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'default_customer_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function distributionAim(): BelongsTo
    {
        return $this->belongsTo(DistributionAim::class);
    }

    /**
     * Monetary invoice types only — EXCLUDES the movement-only 9.x Δελτία
     * Αποστολής, which are not money documents and belong to the Delivery
     * Notes flow (DeliveryNoteSubmitter), never an invoice/quote picker
     * (MYD-003). The ONE shared predicate for every monetary invoice-type
     * selector so the surfaces cannot drift; mirrors Codes::isMovementOnlyType
     * (prefix '9.'). Null-safe: a legacy type with no mydata_type stays
     * selectable (we can't prove it's movement-only, so we don't hide it).
     */
    public function scopeMonetary(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('mydata_type')
            ->orWhere('mydata_type', 'not like', '9.%'));
    }
}
