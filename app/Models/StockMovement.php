<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One signed stock movement (+in / −out) for a tracked product. The product's
 * on-hand quantity is the SUM of these (see App\Services\Stock\StockService) —
 * never a cached column. `source` points back to what caused it.
 */
class StockMovement extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /** Known reasons (free string column; these are the values the app writes). */
    public const REASON_RECEIPT = 'receipt';      // manual goods-in

    public const REASON_INITIAL = 'initial';      // opening απογραφή

    public const REASON_ADJUSTMENT = 'adjustment'; // manual correction (+/−)

    public const REASON_SALE = 'sale';            // out — invoice / Πώληση δελτίο (S2)

    public const REASON_PURCHASE = 'purchase';    // in — supplier expense (S3)

    public const REASON_RETURN = 'return';        // in — credit note / returned goods (S3)

    public const REASON_CANCEL = 'cancel';        // reversal of a cancelled document (S3)

    public const REASON_REVIVE = 'revive';        // un-does a REASON_CANCEL when a cancelled document is restored (Επαναφορά)

    protected $fillable = [
        'company_id',
        'product_id',
        'qty_change',
        'reason',
        'source_type',
        'source_id',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_change' => 'decimal:3',
            'occurred_at' => 'datetime',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
