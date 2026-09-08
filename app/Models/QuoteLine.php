<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line on a quote. Mirrors the editable subset of InvoiceLine with the
 * SAME per-line math:
 *
 *   net_price   = qty × price_per_item × (1 - discount/100)
 *   gross_price = net_price × (1 + vat_percent/100)
 *
 * Computed in a `saving` hook so every path (form, factory, convert) stores
 * correct values. Unlike InvoiceLine this is intentionally LENIENT — a quote
 * is a non-legal draft, so a missing VAT is treated as 0 rather than thrown
 * (nothing gets filed at AADE from here).
 */
class QuoteLine extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            // Auto-stamp company_id from the parent quote (Filament's Repeater
            // ->relationship('lines') only stamps quote_id).
            if (empty($line->company_id) && $line->quote_id) {
                $line->company_id = $line->quote?->company_id
                    ?? Quote::query()->whereKey($line->quote_id)->value('company_id');
            }

            $qty = (float) ($line->qty ?? 0);
            $price = (float) ($line->price_per_item ?? 0);
            $lineDiscount = (float) ($line->discount ?? 0);
            $vat = (float) ($line->vat_percent ?? 0);

            // Clamp the discount to a sane range; quotes are forgiving but a
            // >100% or negative discount would produce nonsense totals.
            if ($lineDiscount < 0) {
                $lineDiscount = 0;
            } elseif ($lineDiscount > 100) {
                $lineDiscount = 100;
            }

            $net = round($qty * $price * (1 - $lineDiscount / 100), 2);
            $gross = round($net * (1 + $vat / 100), 2);

            $line->net_price = $net;
            $line->gross_price = $gross;
        });
    }

    protected $fillable = [
        'company_id',
        'quote_id',
        'product_id',
        'product_descr',
        'qty',
        'price_per_item',
        'discount',
        'vat_percent',
        'net_price',
        'gross_price',
        'metric_unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'price_per_item' => 'decimal:2',
            'discount' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'net_price' => 'decimal:2',
            'gross_price' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
