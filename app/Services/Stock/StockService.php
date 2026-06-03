<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source for stock on-hand. The level is ALWAYS derived (SUM of the
 * ledger) — there is no cached quantity column to drift, mirroring how
 * InvoiceBalance derives money. Stock is informational: nothing here ever blocks
 * a sale/dispatch; an over-sell simply goes negative and surfaces a warning.
 */
class StockService
{
    /** Current on-hand for a tracked product (0.0 for untracked or no movements). */
    public function currentStock(Product $product): float
    {
        if (! $product->track_stock) {
            return 0.0;
        }

        return (float) $product->stockMovements()->sum('qty_change');
    }

    /**
     * Record one signed movement. $source is the document line that caused it
     * (invoice_line / delivery_note_line) or null for a manual entry.
     */
    public function record(
        Product $product,
        float $qtyChange,
        string $reason,
        ?Model $source = null,
        ?string $note = null,
        ?Carbon $occurredAt = null,
    ): StockMovement {
        return $product->stockMovements()->create([
            'company_id' => $product->company_id,
            'qty_change' => $qtyChange,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'note' => $note,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
