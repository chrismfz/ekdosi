<?php

namespace App\Services\Products;

use App\Models\DeliveryNoteLine;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\QuoteLine;
use App\Models\ServiceContract;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds and maintains the variants of a `variable` product
 * (docs/woocommerce-bridge-plan.md §0).
 *
 * A variant is a full Product row copied from its parent (price, VAT, category,
 * myDATA taxes…) so every existing path — stock ledger, invoice lines, myDATA —
 * works on it unchanged. After generation each variant may diverge (e.g. a
 * perfume's 100ml costs more than its 50ml); {@see syncFromParent()} pushes
 * chosen parent fields back down when the operator wants that.
 */
class VariantGenerator
{
    /** Max lengths of the copied identity columns (products.description_short / sku). */
    private const NAME_MAX = 120;

    private const SKU_MAX = 40;

    /**
     * Parent fields copied onto a new variant, grouped for {@see syncFromParent()}.
     *
     * @var array<string, list<string>>
     */
    public const SYNC_GROUPS = [
        'prices' => ['buy_price', 'sell_price', 'price_wvat'],
        'tax' => ['vat_category_id', 'mydata_tax_type', 'mydata_tax_category', 'mydata_tax_per_unit'],
        'catalogue' => ['product_category_id', 'metric_unit_id', 'taric_code', 'supplier'],
        'stock' => ['track_stock', 'reorder_level'],
        'status' => ['is_active'],
    ];

    public const SYNC_GROUP_LABELS = [
        'prices' => 'Τιμές (αγορά / πώληση)',
        'tax' => 'ΦΠΑ & φόροι myDATA',
        'catalogue' => 'Κατηγορία, μονάδα, TARIC, προμηθευτής',
        'stock' => 'Παρακολούθηση αποθέματος & όριο αναπαραγγελίας',
        'status' => 'Ενεργό / ανενεργό',
        'names' => 'Ονομασίες (γονικό — τιμές)',
    ];

    /**
     * A simple product may become a variable parent only while it has NO history:
     * a parent is never sold, so one that already sits on documents or has stock
     * movements would leave that history pointing at a non-sellable grouping.
     */
    public function canBecomeVariable(Product $product): bool
    {
        if ($product->kind !== Product::KIND_SIMPLE) {
            return false;
        }

        $id = $product->getKey();

        // Recurring / WHMCS-mapped products are services wired into renewals — never a grouping.
        if ($product->is_recurring || $product->whmcs_product_id !== null
            || $product->billingPrices()->exists() || $product->priceTiers()->exists()) {
            return false;
        }

        return ! StockMovement::query()->where('product_id', $id)->exists()
            && ! InvoiceLine::query()->where('product_id', $id)->exists()
            && ! QuoteLine::query()->where('product_id', $id)->exists()
            && ! DeliveryNoteLine::query()->where('product_id', $id)->exists()
            && ! ServiceContract::query()->withoutGlobalScopes()->where('product_id', $id)->exists();
    }

    /**
     * Create every missing combination of the given attribute values under $parent.
     * Idempotent: a combination that already exists (even soft-deleted) is skipped.
     *
     * @param  array<int, list<int>>  $valueIdsByAttribute  attribute id => chosen value ids
     * @return Collection<int, Product> the variants created by this call
     */
    public function generate(Product $parent, array $valueIdsByAttribute): Collection
    {
        if (! $parent->isVariable()) {
            throw new InvalidArgumentException('Παραλλαγές δημιουργούνται μόνο σε προϊόν «με παραλλαγές».');
        }

        // Fresh DB state: a just-created parent lacks the column defaults the copy relies on.
        $parent = $parent->fresh() ?? $parent;
        $axes = $this->loadAxes($parent, $valueIdsByAttribute);
        if ($axes === []) {
            return collect();
        }

        // Existing variants built on a different set of axes (e.g. size-only, now colour × size)
        // would get a second, overlapping set — refuse instead of splitting stock across both.
        $requested = collect($axes)->map(fn (array $values) => (int) $values[0]->product_attribute_id)->sort()->values()->all();
        $current = $this->existingAttributeSets($parent);
        if ($current !== [] && $current !== [$requested]) {
            throw new InvalidArgumentException('Οι υπάρχουσες παραλλαγές έχουν άλλα χαρακτηριστικά. Διάλεξε τιμές από τα ίδια χαρακτηριστικά ('
                .'ή διάγραψε πρώτα τις παλιές παραλλαγές).');
        }

        return DB::transaction(function () use ($parent, $axes) {
            $existing = $this->existingSignatures($parent);
            $created = collect();

            foreach ($this->combinations($axes) as $combo) {
                $signature = $this->signature($combo);
                if (isset($existing[$signature])) {
                    continue;
                }

                $variant = Product::create(array_merge(
                    $this->inheritedAttributes($parent),
                    [
                        'company_id' => $parent->company_id,
                        'kind' => Product::KIND_VARIANT,
                        'parent_product_id' => $parent->getKey(),
                        'description_short' => $this->variantName($parent, $combo),
                        'sku' => $this->variantSku($parent, $combo),
                        'date_inserted' => now()->toDateString(),
                    ],
                ));

                foreach ($combo as $value) {
                    $variant->variantValues()->attach($value->getKey(), [
                        'product_attribute_id' => $value->product_attribute_id,
                        'company_id' => $parent->company_id,
                    ]);
                }

                $existing[$signature] = true;
                $created->push($variant);
            }

            return $created;
        });
    }

    /**
     * Copy the chosen field groups (keys of SYNC_GROUPS, plus 'names') from the
     * parent onto every variant. Returns how many variants were updated.
     *
     * @param  list<string>  $groups
     */
    public function syncFromParent(Product $parent, array $groups): int
    {
        $fields = collect($groups)
            ->flatMap(fn (string $g) => self::SYNC_GROUPS[$g] ?? [])
            ->unique()
            ->values()
            ->all();
        $names = in_array('names', $groups, true);

        if ($fields === [] && ! $names) {
            return 0;
        }

        $parent = $parent->fresh() ?? $parent;

        return DB::transaction(function () use ($parent, $fields, $names) {
            $count = 0;
            $parent->variants()->with('variantValues.attribute')->get()
                ->each(function (Product $variant) use ($parent, $fields, $names, &$count) {
                    $changes = $parent->only($fields);
                    if ($names) {
                        $changes['description_short'] = $this->variantName(
                            $parent,
                            $this->orderedValues($variant->variantValues)
                        );
                    }
                    $variant->fill($changes);
                    if ($variant->isDirty()) {
                        $variant->save();
                        $count++;
                    }
                });

            return $count;
        });
    }

    /**
     * «Παντελόνι Nike — Μαύρο / M». The parent part is shortened (never the
     * values) so the distinguishing suffix always survives the 120-char column.
     *
     * @param  iterable<ProductAttributeValue>  $values
     */
    public function variantName(Product $parent, iterable $values): string
    {
        $suffix = ' — '.collect($values)->pluck('value')->implode(' / ');
        $room = max(1, self::NAME_MAX - mb_strlen($suffix));

        return Str::limit((string) $parent->description_short, $room, '').$suffix;
    }

    /**
     * Parent SKU + value codes («NK-PANT-BLK-M»), unique per company (a -2, -3…
     * tail on collision). No parent SKU → no generated SKU.
     *
     * @param  iterable<ProductAttributeValue>  $values
     */
    public function variantSku(Product $parent, iterable $values): ?string
    {
        $base = trim((string) $parent->sku);
        if ($base === '') {
            return null;
        }

        $suffix = '-'.collect($values)->map(fn (ProductAttributeValue $v) => $v->skuCode())->implode('-');
        $candidate = Str::limit($base, max(1, self::SKU_MAX - mb_strlen($suffix)), '').$suffix;
        $candidate = mb_substr($candidate, 0, self::SKU_MAX);

        $n = 1;
        $sku = $candidate;
        while ($this->skuTaken($parent->company_id, $sku)) {
            $n++;
            $tail = '-'.$n;
            $sku = mb_substr($candidate, 0, self::SKU_MAX - mb_strlen($tail)).$tail;
        }

        return $sku;
    }

    /**
     * Resolve the requested values, constrained to the parent's company and to
     * the attribute they were requested under; axes ordered by attribute sort.
     *
     * @param  array<int, list<int>>  $valueIdsByAttribute
     * @return list<list<ProductAttributeValue>>
     */
    private function loadAxes(Product $parent, array $valueIdsByAttribute): array
    {
        $axes = [];
        foreach ($valueIdsByAttribute as $attributeId => $valueIds) {
            $valueIds = array_values(array_filter(array_map('intval', (array) $valueIds)));
            if ($valueIds === []) {
                continue;
            }

            $values = ProductAttributeValue::query()
                ->withoutGlobalScopes()
                ->with('attribute')
                ->where('company_id', $parent->company_id)
                ->where('product_attribute_id', (int) $attributeId)
                ->whereKey($valueIds)
                ->orderBy('sort')
                ->orderBy('id')
                ->get();

            if ($values->count() !== count(array_unique($valueIds))) {
                throw new InvalidArgumentException('Άγνωστη τιμή χαρακτηριστικού για αυτή την εταιρεία.');
            }

            $axes[] = $values->all();
        }

        usort($axes, fn (array $a, array $b) => [$a[0]->attribute->sort, $a[0]->attribute->id]
            <=> [$b[0]->attribute->sort, $b[0]->attribute->id]);

        return $axes;
    }

    /**
     * Cartesian product of the axes.
     *
     * @param  list<list<ProductAttributeValue>>  $axes
     * @return list<list<ProductAttributeValue>>
     */
    private function combinations(array $axes): array
    {
        $result = [[]];
        foreach ($axes as $values) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($values as $value) {
                    $next[] = [...$partial, $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /** @param  iterable<ProductAttributeValue>  $values */
    private function signature(iterable $values): string
    {
        return collect($values)->map(fn ($v) => (int) $v->getKey())->sort()->implode(',');
    }

    /** @return array<string, true> signatures of the parent's existing variants (incl. trashed) */
    private function existingSignatures(Product $parent): array
    {
        return DB::table('product_variant_values')
            ->join('products', 'products.id', '=', 'product_variant_values.product_id')
            ->where('products.parent_product_id', $parent->getKey())
            ->get(['product_variant_values.product_id', 'product_variant_values.product_attribute_value_id'])
            ->groupBy('product_id')
            ->mapWithKeys(fn ($rows) => [
                $rows->pluck('product_attribute_value_id')->map(fn ($id) => (int) $id)->sort()->implode(',') => true,
            ])
            ->all();
    }

    /**
     * @return list<list<int>> the distinct sorted attribute-id sets of the parent's LIVE variants
     *                         (trashed ones are retired — a new axis set may replace them)
     */
    private function existingAttributeSets(Product $parent): array
    {
        return DB::table('product_variant_values')
            ->join('products', 'products.id', '=', 'product_variant_values.product_id')
            ->where('products.parent_product_id', $parent->getKey())
            ->whereNull('products.deleted_at')
            ->get(['product_variant_values.product_id', 'product_variant_values.product_attribute_id'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('product_attribute_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
            ->unique(fn (array $set) => implode(',', $set))
            ->values()
            ->all();
    }

    /**
     * @param  iterable<ProductAttributeValue>  $values
     * @return list<ProductAttributeValue>
     */
    private function orderedValues(iterable $values): array
    {
        return collect($values)
            ->sortBy(fn (ProductAttributeValue $v) => [$v->attribute?->sort ?? 0, $v->product_attribute_id, $v->sort, $v->id])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function inheritedAttributes(Product $parent): array
    {
        $fields = collect(self::SYNC_GROUPS)->flatten()->all();

        return array_merge($parent->only($fields), [
            'description' => $parent->description,
        ]);
    }

    private function skuTaken(int $companyId, string $sku): bool
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->exists();
    }
}
