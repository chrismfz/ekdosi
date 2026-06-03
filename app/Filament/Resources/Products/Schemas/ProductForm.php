<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\BillingCycle;
use App\Filament\Support\Tags\TagControls;
use App\Models\MetricUnit;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Stock\StockService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Product form. The Pricing tab faithfully reproduces the legacy
 * FAddProduct.cpp:103-201 reactive UX:
 *
 *   - operator picks category    → markup% auto-fills from category.markup
 *   - operator types buy_price   → sell_price = buy_price × (1 + markup/100)
 *   - operator types markup%     → sell_price recomputes
 *   - operator picks vat_category→ price_wvat = sell_price × (1 + rate/100)
 *   - operator types sell_price  → price_wvat re-recomputes (don't touch buy/markup)
 *   - operator types price_wvat  → sell_price back-computes (don't touch buy/markup)
 *
 * markup% is NOT persisted (matches legacy — markup lived in Windows
 * Registry as an app-wide default). It exists in the form purely as a UX
 * helper. The per-category override at product_categories.markup is the
 * modern replacement for the legacy Registry setting.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Identity')
                            ->schema([
                                TextInput::make('description_short')
                                    ->label('Description')
                                    ->required()
                                    ->maxLength(120)
                                    ->columnSpan(2),

                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Inactive products stay in the catalogue for invoice history but are hidden from new-invoice pickers.'),

                                Toggle::make('track_stock')
                                    ->label('Παρακολούθηση αποθέματος')
                                    ->default(false)
                                    ->live()
                                    ->helperText('Μέτρα απόθεμα γι\' αυτό το είδος (εμπορεύματα). Άφησέ το κλειστό για υπηρεσίες. Το απόθεμα είναι ενημερωτικό — δεν μπλοκάρει ποτέ πώληση.'),

                                TextInput::make('reorder_level')
                                    ->label('Όριο αναπαραγγελίας')
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn (Get $get) => (bool) $get('track_stock'))
                                    ->helperText('Κάτω από αυτό το απόθεμα → πορτοκαλί «χαμηλό». Κενό = χωρίς ειδοποίηση.'),

                                Placeholder::make('current_stock')
                                    ->label('Τρέχον απόθεμα')
                                    ->visible(fn ($record) => (bool) $record?->track_stock)
                                    ->content(function ($record) {
                                        $n = (float) app(StockService::class)->currentStock($record);
                                        $txt = rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');

                                        return $n < 0 ? "⚠ {$txt} (αρνητικό — backorder)" : $txt;
                                    })
                                    ->helperText('Δες αναλυτικά στο tab «Κινήσεις αποθέματος».'),

                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->maxLength(40)
                                    ->rule(fn ($record) => Rule::unique('products', 'sku')
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->whereNull('deleted_at')
                                        ->ignore($record?->id))
                                    ->helperText('Internal stock code, distinct from barcode. e.g. "HOST-PREM-12M".'),

                                TextInput::make('barcode')
                                    ->maxLength(25)
                                    // Unique per tenant — barcodes are short
                                    // and may collide with other tenants'.
                                    ->rule(fn ($record) => Rule::unique('products', 'barcode')
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->whereNull('deleted_at')
                                        ->ignore($record?->id))
                                    ->helperText('Scannable EAN/UPC. Unique per tenant.'),

                                Select::make('product_category_id')
                                    ->label('Category')
                                    ->required()
                                    ->options(fn () => ProductCategory::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('description_short')
                                        ->pluck('description_short', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        // Update markup_display to reflect the new category's
                                        // default — but do NOT cascade to sell_price /
                                        // price_wvat. Cascading would silently clobber a hand-
                                        // tuned sell_price when operators reclassify a product
                                        // on the edit page (a real bug surfaced in PR #20
                                        // review). Operator who wants to apply the new markup
                                        // explicitly re-types buy_price or markup_display.
                                        $markup = $state
                                            ? (float) (ProductCategory::query()
                                                ->where('company_id', Filament::getTenant()?->getKey())
                                                ->whereKey($state)
                                                ->value('markup') ?? 0)
                                            : 0;
                                        $set('markup_display', $markup);
                                    }),

                                Select::make('metric_unit_id')
                                    ->label('Metric unit')
                                    ->options(fn () => MetricUnit::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Optional — leave blank for services that don\'t have a quantity.'),

                                TextInput::make('supplier')
                                    ->label('Supplier')
                                    ->maxLength(120)
                                    ->helperText('Free-text. For resold items: "cPanel", "Namecheap", etc. Not a foreign key.'),
                            ])
                            ->columns(2),

                        Tab::make('Pricing')
                            ->schema([
                                TextInput::make('buy_price')
                                    ->label('Buy price (net)')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->default(0)
                                    ->prefix('€')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($set, $get) => self::recomputeFromBuy($set, $get)),

                                TextInput::make('markup_display')
                                    ->label('Markup %')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->maxValue(999.99)
                                    ->suffix('%')
                                    ->dehydrated(false)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($set, $get) => self::recomputeFromBuy($set, $get))
                                    ->helperText('Defaults to the selected category\'s markup. Not stored on the product — only used to live-compute sell price.'),

                                Select::make('vat_category_id')
                                    ->label('VAT category')
                                    ->required()
                                    ->options(fn () => VatCategory::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('rate')
                                        ->get()
                                        ->mapWithKeys(fn ($vc) => [$vc->id => $vc->description.' ('.$vc->rate.'%)'])
                                        ->toArray())
                                    ->default(fn () => VatCategory::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->where('is_default', true)
                                        ->value('id'))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(fn ($set, $get) => self::recomputeWvatFromSell($set, $get)),

                                TextInput::make('sell_price')
                                    ->label('Sell price (net)')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->default(0)
                                    ->prefix('€')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($set, $get) => self::recomputeWvatFromSell($set, $get))
                                    ->helperText('Computed from buy × markup, but you can override directly.'),

                                TextInput::make('price_wvat')
                                    ->label('Sell price (with VAT)')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->default(0)
                                    ->prefix('€')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, $set, $get) => self::recomputeSellFromWvat($state, $set, $get))
                                    ->helperText('Type the gross retail price and the net sell price is back-computed.'),
                            ])
                            ->columns(2),

                        Tab::make('Stock')
                            ->schema([
                                TextInput::make('reserve')
                                    ->label('Stock on hand')
                                    ->numeric()
                                    ->step('0.001')
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('Current units in stock. Mostly unused for service tenants; set to 0.'),

                                TextInput::make('reserve_secure')
                                    ->label('Low-stock warning threshold')
                                    ->numeric()
                                    ->step('0.001')
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('Notify when stock-on-hand falls below this. 0 = never notify.'),

                                DatePicker::make('date_inserted')
                                    ->label('Date inserted')
                                    ->helperText('Catalogue entry date. Defaults to today on create.')
                                    ->default(today()),
                            ])
                            ->columns(2),

                        Tab::make('Συνδρομή / Recurring')
                            ->schema([
                                Toggle::make('is_recurring')
                                    ->label('Επαναλαμβανόμενη χρέωση (συνδρομή)')
                                    ->default(false)
                                    ->live()
                                    ->columnSpanFull()
                                    ->helperText('Ενεργοποίησέ το για υπηρεσίες που χρεώνονται περιοδικά (hosting, domains, άδειες). Εμφανίζει τον πίνακα τιμών ανά κύκλο + τις επιλογές παροχής.'),

                                Select::make('provisioning_module')
                                    ->label('Module παροχής')
                                    ->options([
                                        'none' => 'Κανένα (χειροκίνητα)',
                                        'custom' => 'Custom',
                                    ])
                                    ->default('none')
                                    ->visible(fn (Get $get) => (bool) $get('is_recurring'))
                                    ->helperText('Μελλοντικό automation hook (cPanel/mailcow/άδειες). «Κανένα» = χειροκίνητη παροχή.'),

                                TextInput::make('default_suspend_after_days')
                                    ->label('Προεπιλογή αναστολής μετά (ημέρες)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn (Get $get) => (bool) $get('is_recurring'))
                                    ->helperText('Ημέρες ληξιπρόθεσμου πριν την αυτόματη αναστολή. Κενό = χωρίς αυτόματη ενέργεια.'),

                                TextInput::make('default_terminate_after_days')
                                    ->label('Προεπιλογή τερματισμού μετά (ημέρες)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn (Get $get) => (bool) $get('is_recurring'))
                                    ->helperText('Ημέρες ληξιπρόθεσμου πριν τον αυτόματο τερματισμό. Κενό = χωρίς αυτόματη ενέργεια.'),

                                Repeater::make('billingPrices')
                                    ->relationship('billingPrices')
                                    ->label('Τιμές ανά κύκλο χρέωσης')
                                    ->visible(fn (Get $get) => (bool) $get('is_recurring'))
                                    ->columnSpanFull()
                                    ->addActionLabel('+ Προσθήκη κύκλου')
                                    ->schema([
                                        Select::make('billing_cycle')
                                            ->label('Κύκλος')
                                            ->options(BillingCycle::options())
                                            ->required(),
                                        TextInput::make('setup_fee')
                                            ->label('Τέλος εγκατάστασης')
                                            ->numeric()
                                            ->step('0.01')
                                            ->minValue(0)
                                            ->default(0)
                                            ->prefix('€'),
                                        TextInput::make('price')
                                            ->label('Τιμή (καθαρή)')
                                            ->numeric()
                                            ->step('0.01')
                                            ->minValue(0)
                                            ->default(0)
                                            ->prefix('€'),
                                        Toggle::make('is_enabled')
                                            ->label('Ενεργό')
                                            ->default(true),
                                    ])
                                    ->columns(4)
                                    ->helperText('Ένας κύκλος ανά γραμμή (Μηνιαία/Ετήσια…). Κατά τη δημιουργία συμβολαίου ο χειριστής επιλέγει έναν ενεργό κύκλο και η τιμή αντιγράφεται (στιγμιότυπο).'),
                            ])
                            ->columns(2),

                        Tab::make('Notes & integrations')
                            ->schema([
                                Textarea::make('description')
                                    ->label('Public description')
                                    ->rows(6)
                                    ->columnSpanFull()
                                    ->helperText('Shown on invoice line items. Customer-facing.'),

                                Textarea::make('internal_notes')
                                    ->label('Internal notes')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Operator-only. Never appears on invoices. e.g. renewal reminders, supplier quirks.'),

                                TextInput::make('whmcs_product_id')
                                    ->label('WHMCS product ID')
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText('Set by the WHMCS bridge when it lands. Editable manually for now. Unique per tenant.'),

                                TagControls::field()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    // ---- live-compute helpers --------------------------------------------

    /**
     * sell_price = buy_price × (1 + markup/100); then price_wvat refresh.
     * Used when buy_price or markup is explicitly edited by the operator.
     * NOT triggered by category-change anymore — see ProductCategory's
     * afterStateUpdated for why.
     */
    private static function recomputeFromBuy(callable $set, callable $get): void
    {
        $buy = (float) ($get('buy_price') ?? 0);
        $markup = (float) ($get('markup_display') ?? 0);
        $sell = round($buy * (1 + $markup / 100), 2);
        $set('sell_price', $sell);
        self::recomputeWvatFromSell($set, $get, sellOverride: $sell);
    }

    /**
     * price_wvat = sell_price × (1 + vat/100). Used when sell_price or
     * vat_category changes (without touching buy/markup).
     */
    private static function recomputeWvatFromSell(callable $set, callable $get, ?float $sellOverride = null): void
    {
        $sell = $sellOverride ?? (float) ($get('sell_price') ?? 0);
        $rate = self::vatRate($get);
        $set('price_wvat', round($sell * (1 + $rate / 100), 2));
    }

    /**
     * sell_price = price_wvat / (1 + vat/100). Used when operator types
     * gross price directly. Doesn't touch buy/markup.
     */
    private static function recomputeSellFromWvat(mixed $state, callable $set, callable $get): void
    {
        $gross = (float) ($state ?? 0);
        $rate = self::vatRate($get);
        $sell = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : $gross;
        $set('sell_price', $sell);
    }

    private static function vatRate(callable $get): float
    {
        $id = $get('vat_category_id');
        if (! $id) {
            return 0.0;
        }

        return (float) (VatCategory::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->whereKey($id)
            ->value('rate') ?? 0);
    }
}
