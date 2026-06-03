<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a recurring product's per-cycle price matrix (WHMCS-style).
 * Purely catalogue data — no money/InvoiceScope coupling. Distinct from
 * ProductPriceTier (quantity tiers).
 */
class ProductBillingPrice extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'product_id',
        'billing_cycle',
        'setup_fee',
        'price',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'setup_fee' => 'decimal:2',
            'price' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
