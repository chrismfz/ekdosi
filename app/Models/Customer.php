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
        // G6: per-customer auto-email opt-out (default true).
        'auto_email_invoices',
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
        // T-1b: count of WHMCS third-party routing rows this customer owns
        // (0 = not a reseller). Maintained by whmcs:sync-resellers.
        'whmcs_reseller_routes',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'needs_immediate_invoice' => 'boolean',
            'auto_email_invoices' => 'boolean',
            'is_active' => 'boolean',
            'whmcs_reseller_routes' => 'integer',
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

    /**
     * All invoices issued to this customer. Used by the Καρτέλα page
     * for the chronological ledger and yearly breakdown.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * All payments received from this customer. Used by the Καρτέλα
     * page for the chronological ledger + balance calc.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
