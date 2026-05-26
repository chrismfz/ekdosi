<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'type',
        'afm',
        'name',
        'address1',
        'address2',
        'city',
        'postcode',
        'phone1',
        'phone2',
        'fax',
        'occupation',
        'tax_office',
        'kad_primary',
        'details',
        'discount',
        'email',
        'secondary_email',
        'country',
        'vat_vies',
        'withhold_tax',
        'sort_order',
        'alt_customer_legacy_id',
        'payment_method_id',
        'whmcs_client_id',
        // PR-only additions:
        'needs_immediate_invoice',
        'is_active',
        'peppol_endpoint',
        'referred_by_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'needs_immediate_invoice' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tenant relation — required by Filament's BelongsToTenant trait so the
     * resource can scope rows to the current Company.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Self-reference: who referred this customer (if anyone).
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_customer_id');
    }

    /**
     * Inverse: customers this one has referred.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_customer_id');
    }
}
