<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Models\Customer;
use App\Models\Product;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Quote form. Forked from InvoiceForm but stripped of everything legal:
 * no invoice-type / myDATA / withholding / payment-method. A quote is a
 * non-legal draft, editable any time until it's converted to an invoice.
 *
 * Lines support all three sources the operator asked for:
 *   • pick a Product/υπηρεσία (autofills price + VAT + description), OR
 *   • free text (leave the product empty, type a description) — covers a
 *     "προϊόν/υπηρεσία που δεν υπάρχει", OR
 *   • createOptionForm on the product picker = «δημιούργησέ το πρώτο»
 *     (a real Product is created inline and selected).
 */
class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Στοιχεία')
                        ->schema([
                            TextInput::make('subject')
                                ->label('Θέμα προσφοράς')
                                ->maxLength(255)
                                ->columnSpan(2),

                            Select::make('customer_id')
                                ->label('Πελάτης')
                                ->searchable()
                                ->preload(false)
                                ->getSearchResultsUsing(fn (string $search) => Customer::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->where(fn ($q) => $q
                                        ->where('name', 'like', "%{$search}%")
                                        ->orWhere('afm', 'like', "%{$search}%"))
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->afm ? ' ('.$c->afm.')' : '')])
                                    ->toArray())
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
                                ->columnSpan(2),

                            DatePicker::make('issued_at')
                                ->label('Ημερομηνία προσφοράς')
                                ->default(now())
                                ->required(),

                            DatePicker::make('valid_until')
                                ->label('Ισχύει έως')
                                ->default(now()->addDays(30))
                                ->helperText('Πότε λήγει η προσφορά.'),

                            DatePicker::make('service_until')
                                ->label('Λήξη υπηρεσίας (προαιρετικό)')
                                ->helperText('Αν αφορά υπηρεσία: πότε λήγει — για χειροκίνητη παρακολούθηση ανανέωσης.'),

                            TextInput::make('header_discount_percent')
                                ->label('Έκπτωση συνόλου %')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->maxValue(99.99)
                                ->default(0)
                                ->suffix('%'),
                        ])
                        ->columns(2),

                    Tab::make('Γραμμές')
                        ->schema([
                            Repeater::make('lines')
                                ->relationship('lines')
                                ->label(false)
                                ->columnSpanFull()
                                ->itemLabel(fn (array $state) => ($state['product_descr'] ?? null) ?: '(νέα γραμμή)')
                                ->schema([
                                    Select::make('product_id')
                                        ->label('Προϊόν / Υπηρεσία')
                                        ->searchable()
                                        ->preload(false)
                                        ->getSearchResultsUsing(fn (string $search) => Product::query()
                                            ->where('company_id', Filament::getTenant()?->getKey())
                                            ->where('is_active', true)
                                            ->where(fn ($q) => $q
                                                ->where('description_short', 'like', "%{$search}%")
                                                ->orWhere('sku', 'like', "%{$search}%")
                                                ->orWhere('barcode', 'like', "%{$search}%"))
                                            ->orderBy('description_short')
                                            ->limit(50)
                                            ->pluck('description_short', 'id')
                                            ->toArray())
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
                                            $set('vat_percent', (float) ($product->vatCategory?->rate ?? 24));
                                            $set('metric_unit', $product->metricUnit?->name);
                                        })
                                        ->helperText('Άφησέ το κενό για ελεύθερο κείμενο, ή φτιάξε νέο με το +.')
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
                                                'vat_category_id' => $data['vat_category_id'] ?? null,
                                                'is_active' => true,
                                            ]);

                                            return $product->getKey();
                                        })
                                        ->columnSpan(2),

                                    TextInput::make('product_descr')
                                        ->label('Περιγραφή')
                                        ->columnSpan(2)
                                        ->helperText('Ελεύθερο κείμενο — υπερισχύει της περιγραφής του προϊόντος.'),

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
                                        ->label('Τιμή μονάδας (καθαρή)')
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

                                    TextInput::make('vat_percent')
                                        ->label('ΦΠΑ %')
                                        ->numeric()
                                        ->step('0.01')
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->default(24)
                                        ->suffix('%'),

                                    Textarea::make('notes')
                                        ->label('Σημειώσεις γραμμής')
                                        ->rows(2)
                                        ->columnSpanFull(),
                                ])
                                ->addActionLabel('+ Προσθήκη γραμμής')
                                ->reorderable(false),
                        ]),

                    Tab::make('Στοιχεία πελάτη')
                        ->schema([
                            Section::make()
                                ->description('Συμπληρώνονται αυτόματα από τον πελάτη· μπορείς να τα αλλάξεις. Αντιγράφονται στο παραστατικό κατά τη μετατροπή.')
                                ->schema([
                                    TextInput::make('company_name')->label('Επωνυμία')->columnSpan(2),
                                    TextInput::make('vat_no')->label('ΑΦΜ'),
                                    TextInput::make('vies_vat')->label('VIES VAT'),
                                    TextInput::make('occupation')->label('Δραστηριότητα'),
                                    TextInput::make('address1')->label('Διεύθυνση')->columnSpan(2),
                                    TextInput::make('address2')->label('Διεύθυνση (γραμμή 2)')->columnSpan(2),
                                    TextInput::make('city')->label('Πόλη'),
                                    TextInput::make('postcode')->label('Τ.Κ.'),
                                    TextInput::make('country')->label('Χώρα')->maxLength(60),
                                ])
                                ->columns(2),
                        ]),

                    Tab::make('Σημειώσεις')
                        ->schema([
                            Textarea::make('proposal_text')
                                ->label('Κείμενο πρότασης (κορυφή)')
                                ->rows(4)
                                ->columnSpanFull()
                                ->helperText('Εμφανίζεται στην κορυφή της προσφοράς.'),
                            Textarea::make('customer_notes')
                                ->label('Σημειώσεις προς πελάτη (υποσέλιδο)')
                                ->rows(4)
                                ->columnSpanFull(),
                            Textarea::make('admin_notes')
                                ->label('Ιδιωτικές σημειώσεις')
                                ->rows(3)
                                ->columnSpanFull()
                                ->helperText('Δεν εμφανίζονται στον πελάτη.'),
                        ]),
                ]),
        ]);
    }
}
