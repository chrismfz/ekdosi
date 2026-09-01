<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Filament\Support\PickerOptions;
use App\Filament\Support\VatRateOptions;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Quote form — single-page, Excel-style lines (Filament 5 Repeater ->table()).
 *
 * Reworked from the tabbed version for fast "κόψιμο": everything on one screen,
 * the lines as a column grid (Product | Περιγραφή | Ποσότ. | Μ.Μ. | Τιμή |
 * Έκπτ.% | ΦΠΑ%) like Epsilon Smart. All the line logic (product autofill,
 * inline-create, free text) is unchanged — only the layout moved from Tab to
 * Section + table().
 *
 * Lines support all three sources the operator asked for:
 *   • pick a Product/υπηρεσία (autofills price + VAT + description), OR
 *   • free text (leave the product empty, type a description), OR
 *   • createOptionForm on the product picker = «δημιούργησέ το πρώτο».
 */
class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ─── Κεφαλίδα: θέμα + πελάτης + ημερομηνίες ───
            Section::make()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('subject')
                        ->label('Θέμα προσφοράς')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Select::make('customer_id')
                        ->label('Πελάτης')
                        ->searchable()
                        // Favourites + most-billed shown on OPEN; typing falls
                        // through to the search closure (shared with InvoiceForm).
                        ->options(fn () => PickerOptions::favouriteCustomerOptions())
                        ->getSearchResultsUsing(fn (string $search) => PickerOptions::searchCustomerOptions($search))
                        ->getOptionLabelUsing(fn ($value) => optional(Customer::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->find($value))->name)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if (! $state) {
                                return;
                            }
                            $customer = Customer::find($state);
                            if (! $customer) {
                                return;
                            }
                            $set('company_name', $customer->name);
                            $set('vat_no', $customer->afm);
                            $set('vies_vat', $customer->vat_vies);
                            $set('occupation', $customer->occupation);
                            $set('address1', $customer->address1);
                            $set('address2', $customer->address2);
                            $set('city', $customer->city);
                            $set('postcode', $customer->postcode);
                            $set('country', $customer->country ?: 'GR');
                        })
                        ->helperText('Προαιρετικό — μπορείς να φτιάξεις προσφορά και χωρίς καταχωρημένο πελάτη.')
                        ->columnSpanFull(),

                    // Leads L1: set when the quote is started from a lead
                    // («Νέα προσφορά» on the lead) — see CreateQuote::afterFill.
                    Hidden::make('lead_id'),

                    DatePicker::make('issued_at')
                        ->label('Ημερομηνία')
                        ->default(now())
                        ->required(),

                    DatePicker::make('valid_until')
                        ->label('Ισχύει έως')
                        ->default(now()->addDays(30)),

                    DatePicker::make('service_until')
                        ->label('Λήξη υπηρεσίας (προαιρετικό)')
                        ->helperText('Αν αφορά υπηρεσία: για χειροκίνητη παρακολούθηση ανανέωσης.'),

                    TextInput::make('header_discount_percent')
                        ->label('Έκπτωση συνόλου %')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->maxValue(99.99)
                        ->default(0)
                        ->suffix('%'),
                ]),

            // ─── Γραμμές (Excel-style) ───
            Section::make('Γραμμές')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->relationship('lines')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Προϊόν / Υπηρεσία')->width('22%'),
                            TableColumn::make('Περιγραφή')->width('26%'),
                            TableColumn::make('Ποσότητα')->width('9%'),
                            TableColumn::make('Μ.Μ.')->width('8%'),
                            TableColumn::make('Τιμή')->width('11%'),
                            TableColumn::make('Έκπτ. %')->width('9%'),
                            TableColumn::make('ΦΠΑ %')->width('9%'),
                        ])
                        ->schema([
                            Select::make('product_id')
                                ->label('Προϊόν / Υπηρεσία')
                                ->searchable()
                                // Favourites + most-sold shown on OPEN; typing
                                // falls through to the search closure (shared).
                                ->options(fn () => PickerOptions::favouriteProductOptions())
                                ->getSearchResultsUsing(fn (string $search) => PickerOptions::searchProductOptions($search))
                                ->getOptionLabelUsing(fn ($value) => optional(Product::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->find($value))->description_short)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if (! $state) {
                                        return;
                                    }
                                    $product = Product::with('vatCategory', 'metricUnit')->find($state);
                                    if (! $product) {
                                        return;
                                    }
                                    $set('product_descr', $product->description_short);
                                    $set('price_per_item', (float) $product->sell_price);
                                    // Normalised so the value matches a VAT-rate Select option.
                                    $set('vat_percent', VatRateOptions::normalize($product->vatCategory?->rate ?? 24));
                                    $set('metric_unit', $product->metricUnit?->name);
                                })
                                ->createOptionForm([
                                    TextInput::make('description_short')
                                        ->label('Σύντομη περιγραφή')
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('sell_price')
                                        ->label('Τιμή (καθαρή)')
                                        ->numeric()
                                        ->default(0)
                                        ->prefix('€'),
                                    // product_category_id is NOT NULL (restrictOnDelete) — required
                                    // here or the inline create hits an integrity violation.
                                    Select::make('product_category_id')
                                        ->label('Κατηγορία')
                                        ->options(fn () => ProductCategory::query()
                                            ->where('company_id', Filament::getTenant()?->getKey())
                                            ->orderBy('description_short')
                                            ->pluck('description_short', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->required(),
                                    Select::make('vat_category_id')
                                        ->label('Κατηγορία ΦΠΑ')
                                        ->options(fn () => VatCategory::query()
                                            ->where('company_id', Filament::getTenant()?->getKey())
                                            ->orderBy('rate')
                                            ->get()
                                            ->mapWithKeys(fn ($v) => [$v->id => $v->rate.'%'])
                                            ->toArray())
                                        ->required(),
                                ])
                                ->createOptionUsing(function (array $data) {
                                    $product = Product::create([
                                        'company_id' => Filament::getTenant()?->getKey(),
                                        'description_short' => $data['description_short'],
                                        'sell_price' => $data['sell_price'] ?? 0,
                                        'product_category_id' => $data['product_category_id'],
                                        'vat_category_id' => $data['vat_category_id'],
                                        'is_active' => true,
                                    ]);

                                    return $product->getKey();
                                }),

                            TextInput::make('product_descr')
                                ->label('Περιγραφή')
                                ->placeholder('Ελεύθερο κείμενο ή από προϊόν'),

                            TextInput::make('qty')
                                ->label('Ποσότητα')
                                ->required()
                                ->numeric()
                                ->step('0.001')
                                ->default(1)
                                ->minValue(0.001),

                            TextInput::make('metric_unit')
                                ->label('Μ.Μ.')
                                ->maxLength(15),

                            TextInput::make('price_per_item')
                                ->label('Τιμή μονάδας')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->default(0)
                                ->prefix('€'),

                            TextInput::make('discount')
                                ->label('Έκπτωση %')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->maxValue(100)
                                ->default(0)
                                ->suffix('%'),

                            Select::make('vat_percent')
                                ->label('ΦΠΑ %')
                                ->options(fn () => VatRateOptions::options())
                                ->default(VatRateOptions::normalize(24))
                                // allowHtml off; native select so it fits a table cell.
                                ->selectablePlaceholder(false),
                        ])
                        ->addActionLabel('+ Προσθήκη γραμμής')
                        ->reorderable(false),
                ]),

            // ─── Στοιχεία πελάτη (snapshot) — collapsed by default ───
            Section::make('Στοιχεία πελάτη')
                ->columnSpanFull()
                ->description('Συμπληρώνονται αυτόματα από τον πελάτη· αντιγράφονται στο παραστατικό κατά τη μετατροπή.')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('company_name')->label('Επωνυμία')->columnSpanFull(),
                    TextInput::make('vat_no')->label('ΑΦΜ'),
                    TextInput::make('vies_vat')->label('VIES VAT'),
                    TextInput::make('occupation')->label('Δραστηριότητα'),
                    TextInput::make('address1')->label('Διεύθυνση')->columnSpanFull(),
                    TextInput::make('address2')->label('Διεύθυνση (γραμμή 2)')->columnSpanFull(),
                    TextInput::make('city')->label('Πόλη'),
                    TextInput::make('postcode')->label('Τ.Κ.'),
                    TextInput::make('country')->label('Χώρα')->maxLength(60),
                ]),

            // ─── Σημειώσεις — collapsed by default ───
            Section::make('Σημειώσεις')
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    Textarea::make('proposal_text')
                        ->label('Κείμενο πρότασης (κορυφή)')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('customer_notes')
                        ->label('Σημειώσεις προς πελάτη (υποσέλιδο)')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('admin_notes')
                        ->label('Ιδιωτικές σημειώσεις')
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Δεν εμφανίζονται στον πελάτη.'),
                    Select::make('language')
                        ->label('Γλώσσα PDF')
                        ->options([
                            'el' => 'Ελληνικά',
                            'en' => 'Αγγλικά',
                            'both' => 'Δίγλωσσο (GR/EN)',
                        ])
                        ->placeholder('Αυτόματο (από χώρα πελάτη)')
                        ->helperText('Κενό = αυτόματο: Ελληνικά για GR, δίγλωσσο για ξένο παραλήπτη.'),
                ]),
        ]);
    }
}
