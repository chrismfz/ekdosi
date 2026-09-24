<?php

namespace App\Filament\Support;

use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\Product;
use Filament\Facades\Filament;

/**
 * Shared option providers for the high-traffic invoice/quote form pickers
 * (Είδος / Πελάτης / Προϊόν). Each returns "favourites first, then auto-top
 * (most-used), then the rest" on open, and a search-on-type variant that
 * keeps favourites biased to the top.
 *
 * Used by both InvoiceForm and QuoteForm so the two screens behave the same.
 * Tenant scoping rides on the ambient Filament tenant; a ⭐ prefix marks the
 * pinned rows (is_favorite, set inline from each resource's list table).
 */
class PickerOptions
{
    /**
     * Browse-all ceiling: if a tenant's catalogue (customers / active products)
     * has AT MOST this many rows, the on-open picker returns the WHOLE list
     * alphabetically (favourites pinned) so the operator can scroll the entire
     * catalogue without typing. Above it, we fall back to the most-used top
     * slice + search (a thousand-row Select is neither useful nor fast to
     * browse — typing is the right UX there). ~200 options render comfortably.
     */
    private const BROWSE_ALL_MAX = 200;

    /**
     * Invoice-type options for the header picker — favourites first, then
     * most-used (invcount, the per-type running counter, is a good proxy).
     * Bounded by show_on_menu so retired types stay hidden.
     *
     * @return array<int, string>
     */
    public static function invoiceTypeOptions(): array
    {
        return InvoiceType::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('show_on_menu', true)
            // Movement-only 9.x (Δελτία Αποστολής) are NOT monetary documents — they
            // belong to the Delivery Notes flow, never this invoice picker. Shared
            // InvoiceType::scopeMonetary so every monetary selector uses ONE rule (MYD-003).
            ->monetary()
            ->orderByDesc('is_favorite')
            ->orderByDesc('invcount')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn ($t) => [$t->id => ($t->is_favorite ? '⭐ ' : '').$t->pickerLabel()])
            ->toArray();
    }

    /**
     * Customers shown when the picker opens (no search yet): favourites
     * first, then the most-billed, capped so we never preload thousands.
     *
     * @return array<int, string>
     */
    public static function favouriteCustomerOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        $query = Customer::query()
            ->where('company_id', $tenantId)
            ->withCount('invoices')
            ->orderByDesc('is_favorite')
            ->orderByDesc('invoices_count')
            ->orderBy('name');

        // Browse-all: keep the SAME ordering (favourites → most-billed → the
        // alphabetical tail), but lift the cap when the catalogue is small enough
        // to scroll the WHOLE list on open without typing. A large catalogue keeps
        // the top slice (+ search) — a thousand-row Select isn't browsable anyway.
        if (Customer::query()->where('company_id', $tenantId)->count() > self::BROWSE_ALL_MAX) {
            $query->limit(30);
        }

        return $query->get()
            ->mapWithKeys(fn ($c) => [$c->id => static::customerLabel($c)])
            ->toArray();
    }

    /**
     * Customer search-on-type — same name/AFM match as the legacy picker,
     * but with favourites biased to the top of the results.
     *
     * @return array<int, string>
     */
    public static function searchCustomerOptions(string $search): array
    {
        return Customer::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('afm', 'like', "%{$search}%"))
            ->orderByDesc('is_favorite')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => static::customerLabel($c)])
            ->toArray();
    }

    /**
     * Products/services shown when a line picker opens (no search yet):
     * favourites first, then most-sold, active only, capped.
     *
     * @return array<int, string>
     */
    public static function favouriteProductOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        $query = Product::query()
            ->where('company_id', $tenantId)
            ->where('is_active', true)
            ->withCount('invoiceLines')
            ->withSum('stockMovements as stock_on_hand', 'qty_change')
            ->orderByDesc('is_favorite')
            ->orderByDesc('invoice_lines_count')
            ->orderBy('description_short');

        // Browse-all (same rationale as customers): lift the cap when the active
        // catalogue is small enough to scroll the whole list on open.
        $total = Product::query()->where('company_id', $tenantId)->where('is_active', true)->count();
        if ($total > self::BROWSE_ALL_MAX) {
            $query->limit(30);
        }

        return $query->get()
            ->mapWithKeys(fn ($p) => [$p->id => static::productLabel($p)])
            ->toArray();
    }

    /**
     * Product search-on-type — same description/sku/barcode match as the
     * legacy picker, favourites biased to the top, active only.
     *
     * @return array<int, string>
     */
    public static function searchProductOptions(string $search): array
    {
        return Product::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('description_short', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%"))
            ->withSum('stockMovements as stock_on_hand', 'qty_change')
            ->orderByDesc('is_favorite')
            ->orderBy('description_short')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($p) => [$p->id => static::productLabel($p)])
            ->toArray();
    }

    private static function customerLabel(Customer $c): string
    {
        return ($c->is_favorite ? '⭐ ' : '').$c->name.($c->afm ? ' ('.$c->afm.')' : '');
    }

    private static function productLabel(Product $p): string
    {
        $label = ($p->is_favorite ? '⭐ ' : '').$p->description_short;

        // S2.5: surface on-hand stock at pick time for tracked products, so the
        // operator sees «(απόθεμα: N)» while invoicing (red-flag a negative).
        if ($p->track_stock) {
            $stock = (float) ($p->stock_on_hand ?? 0);
            $n = rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.');
            $label .= $stock < 0 ? "  ⚠ απόθεμα: {$n}" : "  · απόθεμα: {$n}";
        }

        return $label;
    }
}
