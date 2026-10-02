<?php

namespace App\Filament\Pages;

use App\Actions\CreatePosSale;
use App\Models\Company;
use App\Models\Product;
use App\Services\Products\ProductMediaService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Throwable;
use UnitEnum;

/**
 * «Ταμείο» — retail Point of Sale (docs/woocommerce-bridge-plan.md §11).
 *
 * A barcode scanner is just a keyboard: the scan field keeps focus and Enter adds
 * the item. A product with variants opens its colour × size picker. «Έκδοση»
 * issues a 11.1 receipt on the spot through CreatePosSale (cash in this version)
 * and opens the 80mm receipt for printing. Gate: company `pos_enabled` +
 * View:PointOfSale.
 */
class PointOfSale extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Παραστατικά';

    protected static ?string $navigationLabel = 'Ταμείο';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.point-of-sale';

    /** @var list<array{product_id: int, label: string, qty: float, discount: float}> */
    public array $cart = [];

    public string $scan = '';

    public string $search = '';

    /** A variable parent whose variants are being picked. */
    public ?int $pickParent = null;

    public string $tendered = '';

    /** The last issued receipt (for «Επανεκτύπωση»). */
    public ?int $lastInvoiceId = null;

    public function getTitle(): string
    {
        return 'Ταμείο';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasPos()
            && (bool) auth()->user()?->can('View:PointOfSale');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    // ── scanning / searching ───────────────────────────────────────────────

    /** Enter in the scan field: exact barcode / SKU / internal code. */
    public function scanCode(): void
    {
        $code = trim($this->scan);
        $this->scan = '';
        if ($code === '') {
            return;
        }

        $product = $this->products()
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('internal_code', $code))
            ->first();

        if ($product === null) {
            // Not an exact code → treat it as a search.
            $this->search = $code;
            Notification::make()->warning()->title('Δεν βρέθηκε κωδικός «'.$code.'» — δες τα αποτελέσματα αναζήτησης.')->send();
            $this->dispatch('pos-focus');

            return;
        }

        $this->choose($product->getKey());
    }

    /** A search result was clicked: add it, or open the variant picker. */
    public function choose(int $productId): void
    {
        $product = $this->products()->whereKey($productId)->first();
        if ($product === null) {
            return;
        }

        if ($product->isVariable()) {
            $this->pickParent = $product->getKey();

            return;
        }

        $this->add($product);
        $this->pickParent = null;
        $this->search = '';
        $this->dispatch('pos-focus');
    }

    public function closePicker(): void
    {
        $this->pickParent = null;
        $this->dispatch('pos-focus');
    }

    // ── cart ───────────────────────────────────────────────────────────────

    public function increment(int $index): void
    {
        if (isset($this->cart[$index])) {
            $this->cart[$index]['qty']++;
        }
    }

    public function decrement(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }
        $this->cart[$index]['qty']--;
        if ($this->cart[$index]['qty'] <= 0) {
            $this->remove($index);
        }
    }

    public function remove(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
        $this->dispatch('pos-focus');
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->tendered = '';
        $this->dispatch('pos-focus');
    }

    /** Keep edited qty / discount inside sane bounds. */
    public function updatedCart(): void
    {
        foreach ($this->cart as $i => $line) {
            $this->cart[$i]['qty'] = max(0.001, round((float) $line['qty'], 3));
            $this->cart[$i]['discount'] = min(100, max(0, round((float) $line['discount'], 2)));
        }
    }

    // ── checkout ───────────────────────────────────────────────────────────

    public function checkout(): void
    {
        if ($this->cart === []) {
            Notification::make()->warning()->title('Το καλάθι είναι άδειο.')->send();

            return;
        }

        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Company && static::canAccess(), 403);

        try {
            $invoice = app(CreatePosSale::class)($tenant, array_map(fn (array $l) => [
                'product_id' => $l['product_id'],
                'qty' => $l['qty'],
                'discount' => $l['discount'],
            ], $this->cart));
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Δεν εκδόθηκε')->body($e->getMessage())->persistent()->send();

            return;
        }

        $change = $this->change((float) $invoice->gross_total);
        $this->lastInvoiceId = $invoice->getKey();
        $this->cart = [];
        $this->tendered = '';

        Notification::make()->success()
            ->title('Εκδόθηκε '.$invoice->invcode.' — '.number_format((float) $invoice->gross_total, 2, ',', '.').' €')
            ->body($change !== null ? 'Ρέστα: '.number_format($change, 2, ',', '.').' €' : null)
            ->send();

        $this->dispatch('pos-print', url: $this->receiptUrl($invoice->getKey()));
        $this->dispatch('pos-focus');
    }

    public function reprint(): void
    {
        if ($this->lastInvoiceId !== null) {
            $this->dispatch('pos-print', url: $this->receiptUrl($this->lastInvoiceId));
        }
    }

    // ── view data ──────────────────────────────────────────────────────────

    /** @return Collection<int, Product> */
    public function getSearchResultsProperty(): Collection
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        return $this->products()
            ->where('kind', '!=', Product::KIND_VARIANT)   // variants are reached through their parent
            ->where(fn ($q) => $q->where('description_short', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('internal_code', 'like', "%{$term}%")
                ->orWhere('barcode', $term))
            ->with(['media', 'vatCategory'])
            ->orderBy('description_short')
            ->limit(24)
            ->get();
    }

    /** @return Collection<int, Product> the picked parent's sellable variants, with stock */
    public function getPickerVariantsProperty(): Collection
    {
        if ($this->pickParent === null) {
            return collect();
        }

        return $this->products()
            ->where('parent_product_id', $this->pickParent)
            ->with(['variantValues.attribute', 'vatCategory'])
            ->withSum('stockMovements as stock_on_hand', 'qty_change')
            ->get()
            ->sortBy(fn (Product $v) => $v->orderedVariantValues()->map(fn ($val) => sprintf('%05d-%05d', $val->attribute?->sort ?? 0, $val->sort))->implode('|'))
            ->values();
    }

    public function getPickerParentProperty(): ?Product
    {
        return $this->pickParent === null ? null : Product::query()->find($this->pickParent);
    }

    /** @return list<array{label: string, qty: float, discount: float, unit: float, gross: float}> */
    public function getCartViewProperty(): array
    {
        $products = Product::query()->with('vatCategory')->whereKey(array_column($this->cart, 'product_id'))->get()->keyBy('id');

        return array_map(function (array $line) use ($products): array {
            $product = $products->get($line['product_id']);

            return [
                'label' => $line['label'],
                'qty' => (float) $line['qty'],
                'discount' => (float) $line['discount'],
                'unit' => $product ? CreatePosSale::lineTotals($product, 1)['gross'] : 0.0,
                'gross' => $product ? CreatePosSale::lineTotals($product, (float) $line['qty'], (float) $line['discount'])['gross'] : 0.0,
            ];
        }, $this->cart);
    }

    public function getTotalProperty(): float
    {
        return round(array_sum(array_column($this->cartView, 'gross')), 2);
    }

    public function change(?float $total = null): ?float
    {
        $paid = (float) str_replace(',', '.', $this->tendered);
        $total ??= $this->total;

        return $this->tendered !== '' && $paid >= $total ? round($paid - $total, 2) : null;
    }

    public function photoUrl(Product $product): ?string
    {
        return ProductMediaService::primaryImage($product)?->fileUrl('thumb');
    }

    public function unitPrice(Product $product): float
    {
        return CreatePosSale::lineTotals($product, 1)['gross'];
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** Active sellable products of the tenant — plus variable parents (to pick from). */
    private function products()
    {
        return Product::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('is_active', true);
    }

    private function add(Product $product): void
    {
        foreach ($this->cart as $i => $line) {
            if ($line['product_id'] === $product->getKey() && (float) $line['discount'] === 0.0) {
                $this->cart[$i]['qty']++;

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $product->getKey(),
            'label' => $product->description_short,
            'qty' => 1.0,
            'discount' => 0.0,
        ];
    }

    private function receiptUrl(int $invoiceId): string
    {
        return URL::temporarySignedRoute('pos.receipt', now()->addMinutes(30), [
            'invoice' => $invoiceId,
        ]);
    }
}
