<?php

namespace App\Support\Products;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;

/**
 * On-hand stock of a variable product's variants, laid out for display: colour
 * rows × size columns when the variants have exactly those two axes, otherwise
 * a flat list. Read-only; the numbers come from the stock ledger
 * (SUM(stock_movements.qty_change)), like everywhere else.
 */
class VariantStockGrid
{
    /**
     * @return array{mode: 'matrix'|'list'|'empty', rows?: list<array{label: string, hex: ?string, cells: list<?array{stock: ?float, active: bool}>}>, cols?: list<string>, items?: list<array{label: string, stock: ?float, active: bool}>, total: float}
     */
    public static function for(Product $parent): array
    {
        /** @var Collection<int, Product> $variants */
        $variants = $parent->variants()
            ->with('variantValues.attribute')
            ->withSum('stockMovements as stock_on_hand', 'qty_change')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            return ['mode' => 'empty', 'total' => 0.0];
        }

        $stockOf = fn (Product $v): ?float => $v->track_stock ? (float) ($v->stock_on_hand ?? 0) : null;
        $total = (float) $variants->sum(fn (Product $v) => $stockOf($v) ?? 0);

        $color = self::axis($variants, ProductAttribute::KIND_COLOR);
        $size = self::axis($variants, ProductAttribute::KIND_SIZE);
        $axesPerVariant = $variants->map(fn (Product $v) => $v->variantValues->count())->unique();

        $cells = [];
        $placed = null;
        if ($color && $size && $axesPerVariant->all() === [2]) {
            $placed = 0;
            foreach ($variants as $v) {
                $c = $v->variantValues->firstWhere('product_attribute_id', $color['attribute_id']);
                $s = $v->variantValues->firstWhere('product_attribute_id', $size['attribute_id']);
                if ($c && $s) {
                    $cells[$c->id][$s->id] = ['stock' => $stockOf($v), 'active' => (bool) $v->is_active];
                    $placed++;
                }
            }
        }

        // Matrix only when EVERY variant has a cell — otherwise the list, so nothing
        // (and no stock counted in «Σύνολο») goes missing from the picture.
        if ($placed === $variants->count()) {
            return [
                'mode' => 'matrix',
                'cols' => $size['values']->pluck('value')->all(),
                'rows' => $color['values']->map(fn (ProductAttributeValue $c) => [
                    'label' => $c->value,
                    'hex' => $c->color_hex,
                    'cells' => $size['values']->map(fn (ProductAttributeValue $s) => $cells[$c->id][$s->id] ?? null)->all(),
                ])->all(),
                'total' => $total,
            ];
        }

        return [
            'mode' => 'list',
            'items' => $variants->map(fn (Product $v) => [
                'label' => $v->variantValues
                    ->sortBy(fn ($val) => [$val->attribute?->sort ?? 0, $val->product_attribute_id])
                    ->pluck('value')->implode(' / ') ?: $v->description_short,
                'stock' => $stockOf($v),
                'active' => (bool) $v->is_active,
            ])->all(),
            'total' => $total,
        ];
    }

    /**
     * The (single) attribute of the given kind used by these variants, with its
     * used values in sort order.
     *
     * @param  Collection<int, Product>  $variants
     * @return array{attribute_id: int, values: Collection<int, ProductAttributeValue>}|null
     */
    private static function axis(Collection $variants, string $kind): ?array
    {
        $values = $variants->flatMap(fn (Product $v) => $v->variantValues)
            ->filter(fn (ProductAttributeValue $val) => $val->attribute?->kind === $kind)
            ->unique('id');

        $attributeIds = $values->pluck('product_attribute_id')->unique();
        if ($attributeIds->count() !== 1) {
            return null;
        }

        return [
            'attribute_id' => (int) $attributeIds->first(),
            'values' => $values->sortBy(fn (ProductAttributeValue $v) => [$v->sort, $v->id])->values(),
        ];
    }
}
