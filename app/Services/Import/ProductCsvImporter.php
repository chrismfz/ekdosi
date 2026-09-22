<?php

namespace App\Services\Import;

use App\Models\Company;
use App\Models\MetricUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use App\Models\VatCategory;
use Closure;

/**
 * Products / services from CSV. Matched by code (SKU), then barcode, and —
 * only when the row carries neither — by exact description.
 *
 * Tax is never guessed: a VAT rate the tenant has no category for fails the
 * row; a row with no VAT column gets the tenant's default category (flagged).
 * `sell_price` (net) is authoritative, as on the model — a gross-only row
 * derives the net from the rate, and `price_wvat` is always recomputed from the
 * net. A category or unit named in the file but missing is created on import.
 */
final class ProductCsvImporter extends EntityCsvImporter
{
    /** @var array<string, int> */
    private array $seen = [];

    /** @var array<string, array<string, int>> 'sku'|'barcode' => code => the line that claimed it */
    private array $claimed = [];

    /** @var array<int, float> vat_category_id => rate */
    private array $rates = [];

    /**
     * @param  (Closure(class-string): bool)|null  $mayCreateLookup  may the operator create a
     *                                                               ProductCategory / MetricUnit? null = yes
     */
    public function __construct(private readonly ?Closure $mayCreateLookup = null) {}

    /** A lookup row to be created — refused (row fails) without the right to create one. */
    private function pendingLookup(PlannedRow $planned, string $model, string $key, string $name, string $what): void
    {
        if ($this->mayCreateLookup !== null && ! ($this->mayCreateLookup)($model)) {
            $planned->fail("Δεν υπάρχει {$what} «{$name}» και δεν έχεις δικαίωμα να τη δημιουργήσεις.");

            return;
        }
        $planned->pending[$key] = $name;
    }

    public function entityLabel(): string
    {
        return 'προϊόντων';
    }

    public function fields(): array
    {
        return [
            'description_short' => ['label' => 'Περιγραφή', 'aliases' => ['Όνομα', 'Είδος', 'Προϊόν', 'Υπηρεσία', 'Name', 'Product', 'Title']],
            'sku' => ['label' => 'Κωδικός', 'aliases' => ['SKU', 'Code', 'Item code', 'Κωδικός είδους']],
            'barcode' => ['label' => 'Barcode', 'aliases' => ['EAN', 'Γραμμωτός κώδικας']],
            'sell_price' => ['label' => 'Τιμή χωρίς ΦΠΑ', 'aliases' => ['Τιμή', 'Καθαρή τιμή', 'Τιμή πώλησης', 'Net price', 'Price']],
            'price_wvat' => ['label' => 'Τιμή με ΦΠΑ', 'aliases' => ['Λιανική τιμή', 'Gross price', 'Price incl VAT', 'Price with VAT']],
            'vat_rate' => ['label' => 'ΦΠΑ %', 'aliases' => ['ΦΠΑ', 'Συντελεστής ΦΠΑ', 'VAT', 'VAT %', 'VAT rate']],
            'category' => ['label' => 'Κατηγορία', 'aliases' => ['Category']],
            'unit' => ['label' => 'Μονάδα μέτρησης', 'aliases' => ['Μονάδα', 'ΜΜ', 'Unit']],
            'buy_price' => ['label' => 'Τιμή αγοράς', 'aliases' => ['Κόστος', 'Cost', 'Purchase price']],
            'description' => ['label' => 'Αναλυτική περιγραφή', 'aliases' => ['Long description', 'Details']],
            // Round-trip of our own export (tenant ids) — read, never in the template.
            'vat_category_id' => ['label' => 'vat_category_id', 'aliases' => [], 'template' => false],
            'product_category_id' => ['label' => 'product_category_id', 'aliases' => [], 'template' => false],
            'metric_unit_id' => ['label' => 'metric_unit_id', 'aliases' => [], 'template' => false],
        ];
    }

    public function sampleRow(): array
    {
        return [
            'description_short' => 'Φιλοξενία ιστοσελίδας (ετήσια)', 'sku' => 'HOST-1', 'sell_price' => '120,00',
            'vat_rate' => '24', 'category' => 'Υπηρεσίες', 'unit' => 'Τεμάχια',
        ];
    }

    protected function reset(): void
    {
        $this->seen = [];
        $this->claimed = [];
        $this->rates = [];
    }

    protected function planRow(Company $company, array $row, PlannedRow $planned): void
    {
        $cid = (int) $company->getKey();
        $name = $this->text($row, 'description_short', 120, $planned);
        $sku = $this->text($row, 'sku', 40, $planned);
        $barcode = $this->text($row, 'barcode', 25, $planned);
        $description = $this->text($row, 'description', 5000, $planned);
        $net = $this->decimal($row, 'sell_price', $planned);
        $gross = $this->decimal($row, 'price_wvat', $planned);
        $buy = $this->decimal($row, 'buy_price', $planned);
        $rate = $this->decimal($row, 'vat_rate', $planned);
        if ($rate !== null && $rate > 0 && $rate < 1) {
            $rate *= 100;   // «0,24» → 24
        }
        $planned->label = $name ?? $sku ?? $barcode ?? '—';
        if ($planned->failed()) {
            return;
        }

        $identity = $sku !== null ? 'sku:'.mb_strtolower($sku)
            : ($barcode !== null ? 'barcode:'.$barcode : ($name !== null ? 'name:'.mb_strtolower($name) : null));
        if ($identity !== null && isset($this->seen[$identity])) {
            $planned->fail('Διπλή εγγραφή στο αρχείο (ίδια με τη γραμμή '.$this->seen[$identity].').');

            return;
        }
        if ($identity !== null) {
            $this->seen[$identity] = $planned->line;
        }

        // Each code is unique per tenant: two rows claiming one would roll the
        // whole import back on the unique index — refuse the later row instead.
        foreach (['sku' => $sku, 'barcode' => $barcode] as $column => $code) {
            $key = $code !== null ? mb_strtolower($code) : null;
            if ($key !== null && isset($this->claimed[$column][$key])) {
                $planned->fail("Ο κωδικός «{$code}» ({$column}) υπάρχει ήδη στη γραμμή {$this->claimed[$column][$key]} του αρχείου.");

                return;
            }
        }
        foreach (['sku' => $sku, 'barcode' => $barcode] as $column => $code) {
            if ($code !== null) {
                $this->claimed[$column][mb_strtolower($code)] = $planned->line;
            }
        }

        $products = fn () => Product::withTrashed()->where('company_id', $cid);
        $existing = $sku !== null ? $products()->where('sku', $sku)->first() : null;
        if ($existing === null && $barcode !== null) {
            $byBarcode = $products()->where('barcode', $barcode)->first();
            if ($byBarcode !== null && $sku !== null && filled($byBarcode->sku) && $byBarcode->sku !== $sku) {
                $planned->fail("Το barcode «{$barcode}» ανήκει ήδη στο προϊόν με κωδικό «{$byBarcode->sku}».");

                return;
            }
            $existing = $byBarcode;
        }
        if ($existing === null && $sku === null && $barcode === null && $name !== null) {
            $existing = $products()->where('description_short', $name)->first();
        }

        if ($existing?->trashed()) {
            $planned->fail("Το προϊόν υπάρχει στον κάδο (#{$existing->getKey()}) — επανέφερέ το πρώτα.");

            return;
        }

        $values = ['description_short' => $name, 'sku' => $sku, 'barcode' => $barcode, 'description' => $description, 'buy_price' => $buy];

        if ($existing !== null) {
            // The product keeps its VAT category; the file's rate only derives prices if they agree.
            $existingRate = $this->rateOf((int) $existing->vat_category_id);
            if ($rate !== null && abs($rate - $existingRate) > 0.001) {
                $planned->warn('Ο ΦΠΑ του αρχείου ('.$this->percent($rate).') διαφέρει από του προϊόντος ('.$this->percent($existingRate).') — κρατήθηκε του προϊόντος.');
            }
            $this->dropTakenCode($values, 'sku', $cid, (int) $existing->getKey(), $planned);
            $this->dropTakenCode($values, 'barcode', $cid, (int) $existing->getKey(), $planned);
            if ($existing->metric_unit_id === null) {
                $this->resolveUnit($cid, $row, $values, $planned);
                if ($planned->failed()) {
                    return;
                }
            }
            $fill = $this->blanksToFill($existing, $values, ['buy_price']);

            // Prices move as a pair (price_wvat = sell_price × rate): fill both when
            // the net is blank; a blank gross alone is recomputed from the existing net.
            if ((float) $existing->sell_price == 0.0 && (float) $existing->price_wvat != 0.0) {
                // A gross is on file: the net follows IT, not the file (nothing is overwritten).
                $fill['sell_price'] = round((float) $existing->price_wvat / (1 + $existingRate / 100), 2);
            } elseif ((float) $existing->sell_price == 0.0) {
                [$sell, $wvat] = $this->prices($net, $gross, $existingRate);
                if ($sell !== null) {
                    $fill['sell_price'] = $sell;
                    $fill['price_wvat'] = $wvat;
                }
            } elseif ((float) $existing->price_wvat == 0.0) {
                $fill['price_wvat'] = round((float) $existing->sell_price * (1 + $existingRate / 100), 2);
            }

            $this->settleExisting($planned, $existing, $fill);

            return;
        }

        // VAT: an explicit tenant category id (our export) → the rate column → default.
        $vatId = $this->ownedId(VatCategory::class, $cid, $row['vat_category_id'] ?? null, $planned, 'vat_category_id');
        if ($vatId === null && $rate !== null) {
            $found = $this->vatCategoryForRate($cid, $rate);
            if ($found === null) {
                $planned->fail('Δεν υπάρχει κατηγορία ΦΠΑ '.$this->percent($rate).' — πρόσθεσέ τη στο Setup → Κατηγορίες ΦΠΑ.');

                return;
            }
            if (is_string($found)) {
                $planned->fail('Υπάρχουν πολλές κατηγορίες ΦΠΑ '.$this->percent($rate)." ({$found}) — δεν μαντεύουμε· όρισε μία ως προεπιλογή ή πρόσθεσε το προϊόν χειροκίνητα.");

                return;
            }
            $vatId = $found;
        }

        if ($name === null) {
            $planned->fail('Λείπει η περιγραφή.');

            return;
        }

        if ($vatId === null) {
            $vatId = $this->defaultVatCategory($cid);
            if ($vatId === null) {
                $planned->fail('Δεν υπάρχουν κατηγορίες ΦΠΑ — πρόσθεσέ τες στο Setup → Κατηγορίες ΦΠΑ.');

                return;
            }
            $planned->warn('Χωρίς ΦΠΑ στο αρχείο — μπήκε η προεπιλεγμένη κατηγορία ('.$this->rateLabel($vatId).').');
        }

        $values['vat_category_id'] = $vatId;
        [$values['sell_price'], $values['price_wvat']] = $this->prices($net, $gross, $this->rateOf($vatId));
        $this->resolveCategory($cid, $row, $values, $planned);
        $this->resolveUnit($cid, $row, $values, $planned);
        if ($planned->failed()) {
            return;
        }
        $values['is_active'] = true;

        $planned->action = PlannedRow::CREATE;
        $planned->values = array_filter($values, static fn ($v): bool => $v !== null);
    }

    protected function persist(Company $company, PlannedRow $row): void
    {
        $cid = (int) $company->getKey();
        $values = $row->values;

        if (isset($row->pending['category'])) {
            $values['product_category_id'] = $this->categoryId($cid, $row->pending['category']);
        }
        if (isset($row->pending['unit'])) {
            $values['metric_unit_id'] = $this->unitId($cid, $row->pending['unit']);
        }

        if ($row->action === PlannedRow::CREATE) {
            Product::create(['company_id' => $cid] + $values);

            return;
        }

        Product::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $cid)
            ->findOrFail($row->existingId)
            ->fill($values)
            ->save();
    }

    /**
     * [net, gross] — the net is authoritative; a gross-only row derives it.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function prices(?float $net, ?float $gross, float $rate): array
    {
        if ($net === null && $gross === null) {
            return [null, null];
        }
        $net ??= round($gross / (1 + $rate / 100), 2);

        return [round($net, 2), round($net * (1 + $rate / 100), 2)];
    }

    /** A code this row would FILL onto a record but another product already holds → dropped, flagged. */
    private function dropTakenCode(array &$values, string $column, int $cid, int $selfId, PlannedRow $planned): void
    {
        if ($values[$column] === null) {
            return;
        }
        $taken = Product::withTrashed()->where('company_id', $cid)
            ->where($column, $values[$column])->whereKeyNot($selfId)->exists();
        if ($taken) {
            $planned->warn("«{$values[$column]}» ({$column}) ανήκει σε άλλο προϊόν — δεν συμπληρώθηκε.");
            $values[$column] = null;
        }
    }

    private function resolveCategory(int $cid, array $row, array &$values, PlannedRow $planned): void
    {
        $id = $this->ownedId(ProductCategory::class, $cid, $row['product_category_id'] ?? null, $planned, 'product_category_id');
        $name = trim((string) ($row['category'] ?? ''));

        if ($id === null && $name !== '') {
            $id = ProductCategory::query()->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $cid)->where('description_short', $name)->value('id');
            if ($id === null) {
                $this->pendingLookup($planned, ProductCategory::class, 'category', mb_substr($name, 0, 120), 'κατηγορία');
                if (! $planned->failed()) {
                    $planned->warn("Νέα κατηγορία «{$name}» θα δημιουργηθεί.");
                }

                return;
            }
        }

        if ($id === null) {
            $id = ProductCategory::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $cid)->orderBy('id')->value('id');
            if ($id === null) {
                $this->pendingLookup($planned, ProductCategory::class, 'category', 'Λοιπά', 'κατηγορία');

                return;
            }
        }

        $values['product_category_id'] = (int) $id;
    }

    private function resolveUnit(int $cid, array $row, array &$values, PlannedRow $planned): void
    {
        $id = $this->ownedId(MetricUnit::class, $cid, $row['metric_unit_id'] ?? null, $planned, 'metric_unit_id');
        $name = trim((string) ($row['unit'] ?? ''));

        if ($id === null && $name !== '') {
            $id = MetricUnit::query()->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $cid)->where('name', $name)->value('id');
            if ($id === null) {
                $this->pendingLookup($planned, MetricUnit::class, 'unit', mb_substr($name, 0, 15), 'μονάδα μέτρησης');   // varchar(15)
                if (! $planned->failed()) {
                    $planned->warn("Νέα μονάδα μέτρησης «{$name}» θα δημιουργηθεί.");
                }

                return;
            }
        }

        $values['metric_unit_id'] = $id !== null ? (int) $id : null;
    }

    /** A numeric id from our export, only if it is this tenant's row. */
    private function ownedId(string $model, int $cid, ?string $raw, PlannedRow $planned, string $field): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $id = ctype_digit($raw)
            ? $model::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $cid)->whereKey((int) $raw)->value('id')
            : null;
        if ($id === null) {
            $planned->warn("Το {$field} «{$raw}» δεν ανήκει σε αυτή την εταιρεία — αγνοήθηκε.");
        }

        return $id !== null ? (int) $id : null;
    }

    /**
     * The one VAT category with this rate. Several with the same rate (e.g. two 0%
     * with different exemption reasons) are NOT guessed between — unless exactly
     * one of them is the default.
     *
     * @return int|string|null id, null = none, a string = the ambiguous candidates
     */
    private function vatCategoryForRate(int $cid, float $rate): int|string|null
    {
        $candidates = VatCategory::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $cid)
            ->whereBetween('rate', [$rate - 0.001, $rate + 0.001])
            ->orderBy('id')
            ->get(['id', 'description', 'is_default']);

        if ($candidates->count() > 1) {
            $defaults = $candidates->where('is_default', true);
            if ($defaults->count() !== 1) {
                return $candidates->pluck('description')->implode(' / ');
            }
            $candidates = $defaults;
        }

        return $candidates->isEmpty() ? null : (int) $candidates->first()->id;
    }

    private function defaultVatCategory(int $cid): ?int
    {
        $id = VatCategory::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $cid)
            ->orderByDesc('is_default')->orderByDesc('rate')->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function rateOf(int $vatCategoryId): float
    {
        return $this->rates[$vatCategoryId] ??= (float) VatCategory::query()->withoutGlobalScope(CompanyScope::class)
            ->whereKey($vatCategoryId)->value('rate');
    }

    private function rateLabel(int $vatCategoryId): string
    {
        return $this->percent($this->rateOf($vatCategoryId));
    }

    private function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').'%';
    }

    private function categoryId(int $cid, string $name): int
    {
        $id = ProductCategory::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $cid)->where('description_short', $name)->value('id');

        return (int) ($id ?? ProductCategory::create(['company_id' => $cid, 'description_short' => $name, 'markup' => 0])->getKey());
    }

    private function unitId(int $cid, string $name): int
    {
        $id = MetricUnit::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $cid)->where('name', $name)->value('id');

        return (int) ($id ?? MetricUnit::create(['company_id' => $cid, 'name' => $name])->getKey());
    }
}
