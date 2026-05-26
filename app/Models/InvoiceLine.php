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
 * in FAddInvoice.cpp:269 / FAddInvoice2.cpp:245) lives on the
 * IssueInvoice action and the InvoiceVatBreakdown value object —
 * NOT in this model. The columns here are the persisted results;
 * recompute happens upstream.
 */
class InvoiceLine extends Model
{
    use HasFactory;

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
