<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One line on an issued invoice. Mirrors legacy INVLINES.
 *
 * `product_descr` and `metric_unit` are snapshots at issue-time —
 * matches legacy INVLINES.PRODUCT_DESCR / METRIC_UNIT columns which
 * the INVLINES_BI trigger populated from PRODUCT at insert. Joining
 * through the product relation displays the CURRENT product
 * description, which may have changed since the invoice was filed.
 * Use the snapshot column for legal/audit views.
 *
 * Pricing math (replication of legacy showSums() + calcPrices()
 * in FAddInvoice.cpp:269 / FAddInvoice2.cpp:245):
 *
 *   net_price   = qty × price_per_item × (1 - discount/100)
 *   gross_price = net_price × (1 + vat_percent/100)
 *
 * These are computed automatically in a `saving` Eloquent hook so
 * every code path that creates a line gets correct values —
 * Filament form, ETL, factory, direct create, queue jobs. Bypasses
 * the per-callsite-burden of remembering to compute before save.
 *
 * `company_id` is similarly auto-stamped from the parent invoice if
 * not explicitly set — matches the multi-tenant invariant (a line
 * MUST belong to the same tenant as its invoice) and saves callers
 * from having to look it up manually.
 *
 * The aggregate header totals (Invoice::net_total / gross_total)
 * apply the invoice-level header_discount_percent on TOP of these
 * line totals — see CreateInvoice/EditInvoice afterCreate/afterSave
 * hooks + InvoiceVatBreakdown.
 */
class InvoiceLine extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            // Auto-stamp company_id from the parent invoice. The HasMany
            // ::create() shape that Filament's Repeater uses
            // (->relationship('lines')) only stamps invoice_id, not
            // company_id — without this hook, every form save would hit
            // the NOT NULL constraint. Lookup is cheap (foreign-key
            // index) and only runs when company_id is empty.
            if (empty($line->company_id) && $line->invoice_id) {
                $line->company_id = $line->invoice?->company_id
                    ?? Invoice::query()->whereKey($line->invoice_id)->value('company_id');
            }

            // Compute line totals from qty + price + discount + VAT.
            // Authoritative computation — overwrites any value the
            // caller may have set, on every save. Match legacy
            // FAddInvoice.cpp:269 semantics: round per-line (not
            // intra-formula) for storage. The HEADER discount is
            // applied at the aggregate level by InvoiceVatBreakdown,
            // never here.
            $qty = (float) ($line->qty ?? 0);
            $price = (float) ($line->price_per_item ?? 0);
            $lineDiscount = (float) ($line->discount ?? 0);
            $vat = (float) ($line->vat_percent ?? 0);

            $net = round($qty * $price * (1 - $lineDiscount / 100), 2);
            $gross = round($net * (1 + $vat / 100), 2);

            $line->net_price = $net;
            $line->gross_price = $gross;
        });
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'invoice_id',
        'product_id',
        'qty',
        'price_per_item',
        'discount',
        'vat_percent',
        'net_price',
        'gross_price',
        'product_descr',
        'metric_unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'price_per_item' => 'decimal:2',
            'discount' => 'decimal:4',
            'vat_percent' => 'decimal:2',
            'net_price' => 'decimal:2',
            'gross_price' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Return-quantity tracking. Only populated for credit / return
     * invoice lines.
     */
    public function returnExtras(): HasOne
    {
        return $this->hasOne(ReturnInvoiceExtra::class);
    }
}
