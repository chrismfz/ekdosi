<?php

namespace App\Models\Concerns;

use App\Models\Product;
use InvalidArgumentException;

/**
 * A document line (invoice / quote / delivery note) may never point at a
 * `variable` product: the parent is a non-sellable grouping — the sale (and
 * its stock movement) belongs to one of its variants. The pickers already
 * hide parents; this is the model-level backstop for every other path
 * (API/MCP, imports, future POS + WooCommerce bridge).
 */
trait RejectsVariableProduct
{
    public static function bootRejectsVariableProduct(): void
    {
        static::saving(function ($line): void {
            if (! $line->product_id || ! $line->isDirty('product_id')) {
                return;
            }

            $kind = Product::query()
                ->withoutGlobalScopes()
                ->whereKey($line->product_id)
                ->value('kind');

            if ($kind === Product::KIND_VARIABLE) {
                throw new InvalidArgumentException(
                    'Το προϊόν #'.$line->product_id.' έχει παραλλαγές — στη γραμμή μπαίνει μία από τις παραλλαγές του, όχι το γονικό.'
                );
            }
        });
    }
}
