<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasTags;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-tenant product catalogue row. Mirrors legacy PRODUCT.
 *
 * Stock semantics (verified against legacy FAddProduct.dfm:163,178):
 * - `reserve`        = current stock on hand, in product units (decimal(7,3)
 *                      so fractional units like 1.250 kg work). Label in
 *                      legacy: "Απόθεμα".
 * - `reserve_secure` = low-stock warning threshold, also in units. Label:
 *                      "κατώφλι αποθέματος για ειδοποίηση παραγγελίας".
 *                      Operator-typed reorder point; future inventory
 *                      workflow can use `reserve < reserve_secure` as the
 *                      trigger condition. NOT a percentage.
 *
 * Pricing semantics (verified against FAddProduct.cpp:103-201):
 * - `buy_price`   = wholesale / cost.
 * - `sell_price`  = net retail (what gets stamped onto invoice lines as
 *                   PRICE_PER_ITEM — FAddInvoice.cpp:194).
 * - `price_wvat`  = sell_price × (1 + vat_category.rate/100), denormalised
 *                   so list views can show "with VAT" without joining.
 * - Markup is NOT a column. Legacy reads it from a Windows Registry app
 *   setting; we use product_categories.markup as the per-category default
 *   for the live-compute in the Filament form.
 */
class Product extends Model
{
    use BelongsToCompany;

    use HasFactory, HasTags, SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'barcode',
        'sku',
        'description_short',
        'description',
        'product_category_id',
        'vat_category_id',
        'metric_unit_id',
        'buy_price',
        'sell_price',
        'price_wvat',
        'reserve',
        'reserve_secure',
        'date_inserted',
        // Forward-looking, no legacy source — operator-populated:
        'is_active',
        // Operator-feedback polish: pin frequent products/services to the
        // top of the invoice-line picker (favourites-first + auto-top).
        'is_favorite',
        'internal_notes',
        'whmcs_product_id',
        'supplier',
    ];

    protected function casts(): array
    {
        return [
            'buy_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'price_wvat' => 'decimal:2',
            'reserve' => 'decimal:3',
            'reserve_secure' => 'decimal:3',
            'date_inserted' => 'date',
            'is_active' => 'boolean',
            'is_favorite' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function vatCategory(): BelongsTo
    {
        return $this->belongsTo(VatCategory::class);
    }

    public function metricUnit(): BelongsTo
    {
        return $this->belongsTo(MetricUnit::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class);
    }

    /**
     * Invoice lines that reference this product. Used (via withCount) to
     * order the invoice-line picker "most-used first" when no favourite
     * pins apply.
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
