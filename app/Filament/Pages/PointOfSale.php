<?php

namespace App\Filament\Pages;

use App\Actions\CreatePosSale;
use App\Actions\PosSaleNotIssued;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Product;
use App\Services\Products\ProductMediaService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Locked;
use RuntimeException;
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

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?string $navigationLabel = 'Ταμείο';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.point-of-sale';

    /** @var list<array{product_id: int, qty: float, discount: float}> */
    public array $cart = [];

    public string $search = '';

    /** A variable parent whose variants are being picked. */
    public ?int $pickParent = null;

    public string $tendered = '';

    /** The last issued receipt (for «Επανεκτύπωση») — server-set only. */
    #[Locked]
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

    /**
     * Enter in the scan field: exact barcode / SKU / internal code. The field is
     * cleared CLIENT-side and the code passed in — a server round-trip of the
     * field would overwrite the next scan typed while this one is in flight.
     */
    public function scanCode(string $code = ''): void
    {
        $code = trim($code);
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
            $this->cart[$index]['qty'] = (float) ($this->cart[$index]['qty'] ?? 0) + 1;
        }
        $this->dispatch('pos-focus');
    }

    public function decrement(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }
        $this->cart[$index]['qty'] = (float) ($this->cart[$index]['qty'] ?? 0) - 1;
        if ($this->cart[$index]['qty'] <= 0) {
            $this->remove($index);

            return;
        }
        $this->dispatch('pos-focus');
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

    /**
     * Keep edited qty / discount inside sane bounds. NO refocus here: a blur from
     * the qty field into the discount field lands here, and yanking focus to the
     * scan field would send the discount the cashier types next to scanCode().
     */
    public function updatedCart(): void
    {
        $this->normalizeCart();
    }

    /** The cart is client-writable — coerce every line to a sane shape. */
    private function normalizeCart(): void
    {
        $this->cart = array_values(array_map(fn ($line): array => [
            'product_id' => (int) (is_array($line) ? ($line['product_id'] ?? 0) : 0),
            'qty' => max(0.001, round((float) (is_array($line) && is_scalar($line['qty'] ?? null) ? $line['qty'] : 1), 3)),
            'discount' => min(100, max(0, round((float) (is_array($line) && is_scalar($line['discount'] ?? null) ? $line['discount'] : 0), 2))),
        ], $this->cart));
    }

    // ── checkout ───────────────────────────────────────────────────────────

    public function checkout(): void
    {
        $this->normalizeCart();
        if ($this->cart === []) {
            Notification::make()->warning()->title('Το καλάθι είναι άδειο.')->send();
            $this->dispatch('pos-print-cancel');

            return;
        }

        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company || ! static::canAccess()) {
            $this->dispatch('pos-print-cancel');
            abort(403);
        }

        try {
            $invoice = app(CreatePosSale::class)($tenant, $this->cart);
        } catch (PosSaleNotIssued $e) {
            // The draft EXISTS (and a timed-out filing may already be at AADE):
            // empty the cart so the same sale can't be rung up again as a new
            // document — the retry happens on THAT draft.
            report($e);
            $this->cart = [];
            $this->tendered = '';
            $this->dispatch('pos-print-cancel');
            Notification::make()->danger()->title('Δεν εκδόθηκε')->body($e->getMessage())->persistent()
                ->actions([
                    Action::make('open')->label('Άνοιγμα πρόχειρου #'.$e->invoiceId)
                        ->url(InvoiceResource::getUrl('view', ['record' => $e->invoiceId]), shouldOpenInNewTab: true),
                ])
                ->send();

            return;
        } catch (RuntimeException $e) {
            // Refused before anything was created (settings, an unsellable item):
            // keep the cart so the cashier can fix it.
            $this->dispatch('pos-print-cancel');
            Notification::make()->danger()->title('Δεν εκδόθηκε')->body($e->getMessage())->persistent()->send();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('pos-print-cancel');
            Notification::make()->danger()->title('Δεν εκδόθηκε')
                ->body('Απρόσμενο σφάλμα — δες τα «Παραστατικά» πριν το ξαναχτυπήσεις.')->persistent()->send();

            return;
        }

        $payable = $invoice->payableTotal();
        $change = $this->change($payable);
        $this->lastInvoiceId = $invoice->getKey();
        $this->cart = [];
        $this->tendered = '';
        $url = $this->receiptUrl($invoice->getKey());

        Notification::make()->success()
            ->title('Εκδόθηκε '.$invoice->invcode.' — '.number_format($payable, 2, ',', '.').' €')
            ->body($change !== null ? 'Ρέστα: '.number_format($change, 2, ',', '.').' €' : null)
            ->actions([
                Action::make('print')->label('Εκτύπωση απόδειξης')->url($url, shouldOpenInNewTab: true),
            ])
            ->send();

        $this->dispatch('pos-print', url: $url);
        $this->dispatch('pos-focus');
    }

    public function reprint(): void
    {
        if ($this->lastInvoiceId !== null) {
            $this->dispatch('pos-print', url: $this->receiptUrl($this->lastInvoiceId));
        } else {
            $this->dispatch('pos-print-cancel');
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
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $this->products()
            ->where('kind', '!=', Product::KIND_VARIANT)   // variants are reached through their parent
            ->where(fn ($q) => $q->where('description_short', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('internal_code', 'like', $like)
                ->orWhere('barcode', $term))
            ->with(['media', ...CreatePosSale::withVat()])
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
            ->with(['variantValues.attribute', ...CreatePosSale::withVat()])
            ->withSum('stockMovements as stock_on_hand', 'qty_change')
            ->get()
            ->sortBy(fn (Product $v) => $v->orderedVariantValues()->map(fn ($val) => sprintf('%05d-%05d', $val->attribute?->sort ?? 0, $val->sort))->implode('|'))
            ->values();
    }

    public function getPickerParentProperty(): ?Product
    {
        return $this->pickParent === null ? null : $this->products()->find($this->pickParent);
    }

    /** @return list<array{label: string, qty: float, discount: float, unit: float, gross: float, levy: float}> */
    public function getCartViewProperty(): array
    {
        $products = $this->products()->with(CreatePosSale::withVat())
            ->whereKey(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $this->cart))
            ->get()->keyBy('id');

        return array_map(function (array $line) use ($products): array {
            $product = $products->get((int) ($line['product_id'] ?? 0));
            $qty = (float) ($line['qty'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);
            $totals = $product ? CreatePosSale::lineTotals($product, $qty, $discount) : null;

            return [
                'label' => $product?->description_short ?? '— μη διαθέσιμο είδος —',
                'qty' => $qty,
                'discount' => $discount,
                'unit' => $product ? CreatePosSale::lineTotals($product, 1)['gross'] : 0.0,
                'gross' => $totals['gross'] ?? 0.0,
                'levy' => $totals['levy'] ?? 0.0,
            ];
        }, $this->cart);
    }

    /** Σ line gross + the product-linked levies (bag fee…) — what the receipt's ΣΥΝΟΛΟ will say. */
    public function getTotalProperty(): float
    {
        return round(
            round(array_sum(array_column($this->cartView, 'gross')), 2)
            + round(array_sum(array_column($this->cartView, 'levy')), 2),
            2,
        );
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
            if ((int) ($line['product_id'] ?? 0) === $product->getKey() && (float) ($line['discount'] ?? 0) === 0.0) {
                $this->cart[$i]['qty'] = (float) ($line['qty'] ?? 0) + 1;

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $product->getKey(),
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
