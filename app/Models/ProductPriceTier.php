<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity-break price tier for a Product. Mirrors legacy PROD_PRICE_QTY.
 *
 * Semantic from legacy: a row says "at qty ≥ X, apply value Y (or discount
 * Z%)". Stored as reference data only — legacy NEVER auto-applied tiers at
 * invoice-line time (verified against FAddInvoice.cpp:188-197: the line
 * grabs PRODUCT.SELL_PRICE directly, no PROD_PRICE_QTY lookup). Operator
 * was expected to type the agreed price manually. We preserve that
 * behaviour in the new IssueInvoice flow; tiers are visible on the
 * product detail panel as a hint, not a policy.
 */
class ProductPriceTier extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'product_id',
        'value',
        'discount_percent',
        'qty',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'qty' => 'decimal:3',
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
