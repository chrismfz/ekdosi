<?php

namespace App\Filament\Pages;

use App\Actions\CreatePosSale;
use App\Actions\PosSaleNotIssued;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Http\Controllers\PosReceiptController;
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

    /** An open-price product («ΡΟΥΧΑ 24%») waiting for the cashier's price. */
    public ?int $pricePrompt = null;

    public string $promptPrice = '';

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
            $this->pricePrompt = null;
            $this->promptPrice = '';
            $this->pickParent = $product->getKey();

            return;
        }

        if ($product->isOpenPrice()) {
            $this->pricePrompt = $product->getKey();
            $this->promptPrice = '';
            $this->pickParent = null;
            $this->dispatch('pos-price-focus');

            return;
        }

        $this->add($product);
        $this->pickParent = null;
        $this->closePrice();   // a product picked while a price prompt was open closes it
    }

    /** Enter / «Προσθήκη» in the price prompt: one line at the typed gross price. */
    public function addOpenPrice(): void
    {
        $product = $this->pricePrompt === null ? null : $this->products()->with('vatCategory')->find($this->pricePrompt);
        if ($product === null || ! $product->isOpenPrice()) {
            $this->closePrice();

            return;
        }

        // A barcode scanned while the price box had focus (6+ bare digits — no till
        // price looks like that) is a SCAN, not a price.
        $typed = trim($this->promptPrice);
        if (preg_match('/^\d{6,}$/', $typed) === 1) {
            $this->closePrice();
            $this->scanCode($typed);

            return;
        }

        $price = self::parseAmount($typed);
        if ($price === null || CreatePosSale::unitGross($product, $price) === null) {
            Notification::make()->warning()->title('Μη έγκυρη τιμή «'.$typed.'»')
                ->body('Γράψε την τιμή με ΦΠΑ, π.χ. 24,90 ή 1.250,00 (όχι σκέτο «1.250»).')->send();
            $this->dispatch('pos-price-focus');

            return;
        }

        // Always its own line — two «ΡΟΥΧΑ» at different prices never merge. The line
        // is shelf-priced (POS-2): it charges exactly the typed price.
        $this->cart[] = ['product_id' => $product->getKey(), 'qty' => 1.0, 'discount' => 0.0, 'price' => $price];
        $this->closePrice();
    }

    public function closePrice(): void
    {
        $this->pricePrompt = null;
        $this->promptPrice = '';
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
        $this->refocus();
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
        $this->refocus();
    }

    public function remove(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
        $this->refocus();
    }

    /** Back to the scan field — unless a price is being typed into the open-price prompt. */
    private function refocus(): void
    {
        $this->dispatch($this->pricePrompt === null ? 'pos-focus' : 'pos-price-focus');
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->tendered = '';
        $this->closePrice();
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
            // the typed gross price of an open-price line (ignored for any other product)
            'price' => is_array($line) && is_numeric($line['price'] ?? null) ? round((float) $line['price'], 2) : null,
        ], $this->cart));
    }

    // ── checkout ───────────────────────────────────────────────────────────

    public function checkout(): void
    {
        $this->normalizeCart();
        if ($this->pricePrompt !== null && $this->pricePromptProduct === null) {
            $this->pricePrompt = null;   // its product went away (deactivated) — nothing to finish
        }
        if ($this->pricePrompt !== null) {
            // A typed-but-not-added price would silently drop the item from the receipt.
            Notification::make()->warning()->title('Ολοκλήρωσε πρώτα την τιμή του είδους')
                ->body('Πάτα «Προσθήκη» (ή «Ακύρωση») και μετά «Έκδοση».')->send();
            $this->dispatch('pos-print-cancel');
            $this->dispatch('pos-price-focus');

            return;
        }
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

    /** @return Collection<int, Product> «αγαπημένα» — quick keys while nothing is searched */
    public function getFavoritesProperty(): Collection
    {
        return $this->products()
            ->where('is_favorite', true)
            ->where('kind', '!=', Product::KIND_VARIANT)
            ->with(['media', 'vatCategory'])
            ->orderBy('description_short')
            ->limit(24)
            ->get();
    }

    /** The open-price product waiting for its price. */
    public function getPricePromptProductProperty(): ?Product
    {
        return $this->pricePrompt === null ? null : $this->products()->find($this->pricePrompt);
    }

    public function getPickerParentProperty(): ?Product
    {
        return $this->pickParent === null ? null : $this->products()->find($this->pickParent);
    }

    /** @return list<array{label: string, qty: float, discount: float, unit: float, gross: float, levy: float}> */
    public function getCartViewProperty(): array
    {
        $products = $this->products()->with('vatCategory')
            ->whereKey(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $this->cart))
            ->get()->keyBy('id');

        return array_map(function (array $line) use ($products): array {
            $product = $products->get((int) ($line['product_id'] ?? 0));
            $qty = (float) ($line['qty'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);
            $price = $line['price'] ?? null;
            $totals = $product ? CreatePosSale::lineTotals($product, $qty, $discount, $price) : null;

            return [
                'label' => $product === null ? '— μη διαθέσιμο είδος —'
                    : $product->description_short.(CreatePosSale::vatOf($product) === null ? ' ⚠ χωρίς ενεργό ΦΠΑ' : ''),
                'qty' => $qty,
                'discount' => $discount,
                'unit' => $product ? CreatePosSale::lineTotals($product, 1, 0.0, $price)['gross'] : 0.0,
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
        $paid = self::parseAmount($this->tendered);
        $total ??= $this->total;

        return $paid !== null && $paid >= $total ? round($paid - $total, 2) : null;
    }

    /**
     * A Greek-typed amount → float, STRICT (a misread price is a wrong legal receipt):
     * «24,90» · «24.90» · «24» · «1.250,50» — anything ambiguous or malformed («1.250»,
     * «1,250», «12,5,0», «abc») is null, never a guess.
     */
    public static function parseAmount(string $typed): ?float
    {
        $s = str_replace([' ', "\u{00A0}", '€'], '', trim($typed));
        // A thousands dot ONLY with its decimals («1.250,00»): a lone «1.250» could be
        // 1250 or 1,25 (a numpad «.») — a 1000× error either way, so it's refused.
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $s) === 1) {           // 1.250,50
            return round((float) str_replace(['.', ','], ['', '.'], $s), 2);
        }
        if (preg_match('/^\d+([.,]\d{1,2})?$/', $s) === 1) {                     // 24 · 24,90 · 24.90
            return round((float) str_replace(',', '.', $s), 2);
        }

        return null;
    }

    public function photoUrl(Product $product): ?string
    {
        return ProductMediaService::primaryImage($product)?->fileUrl('thumb');
    }

    /** One unit's shelf price (VAT incl.) — the same number the cart row shows. */
    public function unitPrice(Product $product): float
    {
        return CreatePosSale::lineTotals($product, 1)['gross'];
    }

    /** One unit's product-linked fee (bag, deposit…) — shown NEXT to the price, never inside it. */
    public function unitLevy(Product $product): float
    {
        return round(CreatePosSale::lineTotals($product, 1)['levy'], 2);
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
            if ((int) ($line['product_id'] ?? 0) === $product->getKey() && (float) ($line['discount'] ?? 0) === 0.0 && ($line['price'] ?? null) === null) {
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
        return PosReceiptController::signedUrl($invoiceId);
    }
}
