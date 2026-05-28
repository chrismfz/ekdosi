<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Support\MyData\Codes;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
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
 * Invoice issuance form. Used by both CreateInvoice and EditInvoice
 * (Edit gated to drafts only by EditInvoice's canAccess override).
 *
 * Key design decisions:
 *
 * 1. ΑΑ (code + invcode) is NOT a form field. It's allocated server-
 *    side by InvoiceNumberer in CreateInvoice::handleRecordCreation
 *    under a row lock — same atomic semantics as the legacy
 *    INVOICE_BI1 + INVOICE_AI triggers. Operator never sees a "next
 *    ΑΑ" input because that would race against concurrent issuers.
 *
 * 2. Customer-snapshot fields (address1, city, vat_no, occupation,
 *    etc.) auto-fill on customer-change. Once persisted they're
 *    legally frozen — even if the customer record is edited later,
 *    the invoice shows what was true at issue time.
 *
 * 3. Line totals (net_price, gross_price) are computed server-side
 *    on save from qty × price_per_item × (1 - discount/100) × VAT.
 *    The form fields for these are read-only / hidden — operators
 *    don't type them; the saved values are authoritative.
 *
 * 4. Header total (net_total, gross_total, withhold_amount) likewise
 *    computed server-side via InvoiceVatBreakdown semantics —
 *    rounding at the GROUP-BY-VAT-rate aggregate level to match the
 *    legacy CALCULATE_VAT_FOR_INVOICE stored proc exactly.
 *
 * 5. myDATA mirror columns (mydata_*) are NOT in this form. They're
 *    written exclusively by the MyDataSubmitter service (forceFill
 *    bypasses fillable). The form layer cannot spoof them.
 */
class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Identity')
                        ->schema([
                            Select::make('invoice_type_id')
                                ->label('Invoice type')
                                ->required()
                                ->options(fn () => InvoiceType::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->where('show_on_menu', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn ($t) => [$t->id => $t->code.' — '.$t->name])
                                    ->toArray())
                                ->searchable()
                                ->preload()
                                // Once issued (mydata_state set) the type is frozen — operator
                                // can't reclassify a filed invoice.
                                ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                            DateTimePicker::make('issued_at')
                                ->label('Issued at')
                                ->required()
                                ->default(now())
                                ->seconds(false)
                                ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                            Select::make('customer_id')
                                ->label('Customer')
                                ->required()
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
                                // On customer change, auto-fill the snapshot fields.
                                // Operator can override before save; once saved + filed,
                                // the snapshot is legally frozen.
                                ->afterStateUpdated(function ($state, callable $set, $record) {
                                    if ($record && $record->mydata_state !== null) {
                                        return;  // already filed, don't clobber snapshot
                                    }
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
                                ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                            Select::make('payment_method_id')
                                ->label('Payment method')
                                ->options(fn () => PaymentMethod::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->orderBy('description')
                                    ->pluck('description', 'id'))
                                ->searchable()
                                ->preload(),

                            Select::make('delivery_method_id')
                                ->label('Delivery method')
                                ->options(fn () => DeliveryMethod::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->orderBy('description')
                                    ->pluck('description', 'id'))
                                ->searchable()
                                ->preload(),

                            Select::make('distribution_aim_id')
                                ->label('Distribution aim (Σκοπός διακίνησης)')
                                ->options(fn () => DistributionAim::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->orderBy('description')
                                    ->pluck('description', 'id'))
                                ->searchable()
                                ->preload(),

                            DatePicker::make('delivery_date')
                                ->label('Delivery date'),

                            TextInput::make('header_discount_percent')
                                ->label('Header discount %')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->maxValue(99.99)
                                ->default(0)
                                ->suffix('%')
                                ->helperText('Applied across all lines. Must be < 100 — for full-discount cases, issue a credit invoice instead.'),
                        ])
                        ->columns(2),

                    Tab::make('Lines')
                        ->schema([
                            Repeater::make('lines')
                                ->relationship('lines')
                                ->label(false)
                                ->columnSpanFull()
                                ->itemLabel(fn (array $state) => ($state['product_descr'] ?? null) ?: '(new line)')
                                ->schema([
                                    Select::make('product_id')
                                        ->label('Product')
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
                                        ->columnSpan(2),

                                    TextInput::make('product_descr')
                                        ->label('Description (frozen)')
                                        ->columnSpan(2)
                                        ->helperText('Snapshot at issue time. Overrides the product description on the printed invoice.'),

                                    TextInput::make('qty')
                                        ->label('Qty')
                                        ->required()
                                        ->numeric()
                                        ->step('0.001')
                                        ->default(1)
                                        ->minValue(0.001),

                                    TextInput::make('metric_unit')
                                        ->label('Unit')
                                        ->maxLength(15),

                                    TextInput::make('price_per_item')
                                        ->label('Unit price (net)')
                                        ->numeric()
                                        ->step('0.01')
                                        ->minValue(0)
                                        ->prefix('€'),

                                    TextInput::make('discount')
                                        ->label('Discount %')
                                        ->numeric()
                                        ->step('0.0001')
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->default(0)
                                        ->suffix('%'),

                                    TextInput::make('vat_percent')
                                        ->label('VAT %')
                                        ->required()
                                        ->numeric()
                                        ->step('0.01')
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->suffix('%'),

                                    Textarea::make('notes')
                                        ->rows(2)
                                        ->columnSpanFull(),
                                ])
                                ->addActionLabel('+ Add line')
                                ->reorderable(false)
                                ->disabled(fn ($record) => $record && $record->mydata_state !== null),
                        ]),

                    Tab::make('Customer snapshot')
                        ->schema([
                            Section::make()
                                ->description('Frozen at issue time. Auto-fills from the customer on customer-select; you can override before save. Once filed at myDATA, the snapshot is legally locked.')
                                ->schema([
                                    TextInput::make('company_name')->label('Name on invoice')->columnSpan(2),
                                    TextInput::make('vat_no')->label('ΑΦΜ'),
                                    TextInput::make('vies_vat')->label('VIES VAT'),
                                    TextInput::make('occupation')->label('Δραστηριότητα'),
                                    TextInput::make('address1')->label('Address')->columnSpan(2),
                                    TextInput::make('address2')->label('Address (line 2)')->columnSpan(2),
                                    TextInput::make('city'),
                                    TextInput::make('postcode'),
                                    TextInput::make('country')->maxLength(60)->helperText('ISO alpha-2 preferred. Normalised at submit time.'),
                                ])
                                ->columns(2),
                        ])
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                    Tab::make('Notes')
                        ->schema([
                            Textarea::make('notes')->rows(6)->columnSpanFull()->label('Internal / printed notes'),
                            TextInput::make('withhold_amount')
                                ->label('Withholding amount (€)')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->prefix('€')
                                ->live(onBlur: true)
                                ->helperText('Παρακράτηση φόρου — typically 20% on services. Stamped on the invoice; subtracted from amount payable.'),

                            // G1: AADE needs the withholding CATEGORY (§8.4) to
                            // file the taxesTotals block. Required whenever an
                            // amount is set; depends on the service (fees 20%,
                            // technicians 4/10%, lawyers 15%, …).
                            Select::make('withhold_category')
                                ->label('Withholding category (myDATA §8.4)')
                                ->options(collect(Codes::WITHHOLDING_CATEGORIES)
                                    ->mapWithKeys(fn (int $c) => [$c => 'Κατηγορία '.$c])
                                    ->all())
                                ->searchable()
                                ->required(fn (Get $get) => (float) ($get('withhold_amount') ?? 0) > 0)
                                ->helperText('Υποχρεωτικό όταν υπάρχει ποσό παρακράτησης — καθορίζει τον τύπο (π.χ. αμοιβές 20%, μηχανικοί 4/10%, δικηγόροι 15%).'),
                        ]),
                ]),
        ]);
    }
}
