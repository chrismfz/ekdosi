<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasTags;
use App\Services\Products\ProductMediaService;
use App\Services\Products\VariantGenerator;
use App\Support\MyData\Taric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *
 * Variants (`kind`): a `variable` product is a NON-sellable grouping («Παντελόνι
 * Nike»); each `variant` («… — Μαύρο / M») is a full product row of its own (own
 * stock, SKU, barcode, price) pointing at it via `parent_product_id`. Pickers use
 * {@see scopeSellable()} so a variable parent can never land on a document line.
 */
class Product extends Model
{
    use BelongsToCompany;
    use HasFactory, HasTags, SoftDeletes;

    public const KIND_SIMPLE = 'simple';

    public const KIND_VARIABLE = 'variable';

    public const KIND_VARIANT = 'variant';

    /** @var list<string>|null disks noted in forceDeleting, purged in forceDeleted */
    public ?array $mediaDisksBeforeForceDelete = null;

    /** Mirror the column default so a freshly created (un-refreshed) model knows its kind. */
    protected $attributes = [
        'kind' => self::KIND_SIMPLE,
    ];

    /**
     * TARIC is stored NORMALISED (10 chars: an 8-digit ΣΟ code +«00») whatever the entry
     * path — form, CSV import, inline create, API. An unparseable value is kept trimmed so
     * the bad input stays visible (the form only offers catalog codes; AADE would reject it) rather than vanish.
     */
    public function setTaricCodeAttribute(?string $value): void
    {
        $this->attributes['taric_code'] = Taric::normalize($value)
            ?? (trim((string) $value) === '' ? null : mb_substr(trim((string) $value), 0, 10));
    }

    protected $fillable = [
        'taric_code',
        'company_id',
        'kind',
        'parent_product_id',
        'internal_code',
        'legacy_id',
        'barcode',
        'sku',
        'description_short',
        'description',
        'product_category_id',
        'vat_category_id',
        'mydata_tax_type',
        'mydata_tax_category',
        'mydata_tax_per_unit',
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
        // «Ταμείο»: the cashier types the price (generic «ΡΟΥΧΑ 24%» items).
        'pos_open_price',
        'internal_notes',
        'whmcs_product_id',
        'supplier',
        'track_stock',
        'reorder_level',
        // Recurring / provisioning catalogue metadata (per-cycle prices live in
        // product_billing_prices). provisioning_module is a free-form key.
        'is_recurring',
        'provisioning_module',
        'module_meta',
        'default_suspend_after_days',
        'default_terminate_after_days',
        // PR-D: per-product dunning master switch (default OFF).
        'dunning_enabled',
    ];

    protected function casts(): array
    {
        return [
            'buy_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'mydata_tax_type' => 'integer',
            'mydata_tax_category' => 'integer',
            'mydata_tax_per_unit' => 'decimal:4',
            'price_wvat' => 'decimal:2',
            'reserve' => 'decimal:3',
            'reserve_secure' => 'decimal:3',
            'date_inserted' => 'date',
            'is_active' => 'boolean',
            'is_favorite' => 'boolean',
            'pos_open_price' => 'boolean',
            'track_stock' => 'boolean',
            'reorder_level' => 'decimal:3',
            'is_recurring' => 'boolean',
            'module_meta' => 'array',
            'dunning_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Backstop for EVERY restore path (row action, bulk action, code): a variant
        // whose parent is gone or whose axes no longer match its live siblings stays
        // trashed. The UI actions check the same rule first to explain why.
        static::restoring(fn (Product $product) => app(VariantGenerator::class)->restoreBlocker($product) === null);

        // A force-delete cascades product_media rows in the DB without model events:
        // note the disks BEFORE, drop the product's media directory AFTER it commits
        // (a failed/vetoed delete keeps its files). Block bodies: a non-null return
        // from an «-ing» listener would halt the listeners after it.
        static::forceDeleting(function (Product $product): void {
            $product->mediaDisksBeforeForceDelete = app(ProductMediaService::class)->productDisks($product);
        });
        static::forceDeleted(function (Product $product): void {
            $disks = $product->mediaDisksBeforeForceDelete ?? [];
            $companyId = (int) $product->company_id;
            $productId = (int) $product->getKey();
            DB::afterCommit(fn () => app(ProductMediaService::class)->purgeFiles($disks, $companyId, $productId));
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Photos, videos and video links (unordered — ProductMediaService::displayMedia orders; the media tab reorders by `sort`). */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_product_id');
    }

    /** A variant's axis values («Μαύρο», «M»), ordered by attribute then value. */
    public function variantValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'product_variant_values')
            ->withPivot('product_attribute_id', 'company_id')
            ->withTimestamps();
    }

    /**
     * A variant's values in the ONE canonical order (attribute sort, then value
     * sort) — used for its name, its label in the variants tab, grid and form.
     *
     * @return Collection<int, ProductAttributeValue>
     */
    public function orderedVariantValues(): Collection
    {
        $this->loadMissing('variantValues.attribute');

        return self::orderValues($this->variantValues);
    }

    /**
     * @param  iterable<ProductAttributeValue>  $values
     * @return Collection<int, ProductAttributeValue>
     */
    public static function orderValues(iterable $values): Collection
    {
        return collect($values)
            ->sortBy(fn (ProductAttributeValue $v) => [$v->attribute?->sort ?? 0, $v->product_attribute_id, $v->sort, $v->id])
            ->values();
    }

    public function isVariable(): bool
    {
        return $this->kind === self::KIND_VARIABLE;
    }

    public function isVariant(): bool
    {
        return $this->kind === self::KIND_VARIANT;
    }

    /** Everything that can go on a document line — i.e. not a variable (grouping) parent. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('kind'), '!=', self::KIND_VARIABLE);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * «Ταμείο»: an open-price generic item (the cashier types its price). Only a
     * SIMPLE product — a variant/variable sells at its own catalogue price.
     */
    public function isOpenPrice(): bool
    {
        return (bool) $this->pos_open_price && ($this->kind ?? self::KIND_SIMPLE) === self::KIND_SIMPLE;
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

    /** Per-cycle recurring price matrix (WHMCS-style). */
    public function billingPrices(): HasMany
    {
        return $this->hasMany(ProductBillingPrice::class);
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
