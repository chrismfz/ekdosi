<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Support\MyData\Codes;
use App\Support\MyData\ReverseCharge;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
            // ─── Κεφαλίδα: τύπος, πελάτης, τρόποι, ημερομηνίες ───
            Section::make()
                ->columns(2)
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

                            // Reverse-charge hint: EU non-GR customer with a VAT id →
                            // this is (almost certainly) an intra-community supply that
                            // should be invoiced at 0% with §8.3 reason 16 (άρθρο 45).
                            // We don't force it (the operator chooses the 0% VAT category
                            // per line) — just a one-time nudge so it isn't forgotten.
                            if (ReverseCharge::appliesTo($customer)) {
                                $tenant = Filament::getTenant();
                                $autoDefaults = $tenant && ReverseCharge::shouldDefaultZeroVat($tenant, $customer);
                                Notification::make()
                                    ->title('Ενδοκοινοτική παράδοση (reverse charge)')
                                    ->body('Πελάτης ΕΕ ('.strtoupper((string) $customer->country).') με ΑΦΜ/ΦΠΑ. '
                                        .($autoDefaults
                                            ? 'Οι νέες γραμμές προεπιλέγονται σε 0% ΦΠΑ (αιτία «16 — άρθρο 45»). Αλλάξτε ανά γραμμή αν χρειάζεται.'
                                            : 'Συνήθως 0% ΦΠΑ με αιτία «16 — άρθρο 45» — ρυθμίστε ΜΙΑ 0% κατηγορία ΦΠΑ με αιτία εξαίρεσης (Setup → VAT Categories) για αυτόματη προεπιλογή.'))
                                    ->info()->send();
                            }
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
                        ->helperText('Applied across all lines. Must be < 100.'),
                ]),

            // ─── Γραμμές (Excel-style) ───
            Section::make('Γραμμές')
                ->schema([
                    Repeater::make('lines')
                        ->relationship('lines')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Προϊόν')->width('18%'),
                            TableColumn::make('Περιγραφή')->width('20%'),
                            TableColumn::make('Ποσότ.')->width('8%'),
                            TableColumn::make('Μ.Μ.')->width('7%'),
                            TableColumn::make('Τιμή (καθ.)')->width('10%'),
                            TableColumn::make('Τιμή (με ΦΠΑ)')->width('11%'),
                            TableColumn::make('Έκπτ.%')->width('8%'),
                            TableColumn::make('ΦΠΑ%')->width('8%'),
                            TableColumn::make('Σημ.')->width('10%'),
                        ])
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
                                ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                    if (! $state) {
                                        return;
                                    }
                                    $product = Product::with('vatCategory', 'metricUnit')->find($state);
                                    if (! $product) {
                                        return;
                                    }
                                    $net = (float) $product->sell_price;
                                    // Reverse-charge default: for an EU-non-GR customer
                                    // (with a single configured 0% exemption category)
                                    // a picked product defaults to 0% instead of its own
                                    // rate — the operator can still override per line.
                                    // $get('../../customer_id') reads the parent invoice's
                                    // customer from inside the lines repeater.
                                    $vat = self::reverseChargeApplies($get('../../customer_id'))
                                        ? 0.0
                                        : (float) ($product->vatCategory?->rate ?? 24);
                                    $set('product_descr', $product->description_short);
                                    $set('price_per_item', $net);
                                    $set('vat_percent', $vat);
                                    // G7: keep the VAT-inclusive mirror in sync.
                                    $set('price_per_item_wvat', self::grossFromNet($net, $vat));
                                    $set('metric_unit', $product->metricUnit?->name);
                                }),

                            TextInput::make('product_descr')
                                ->label('Description (frozen)')
                                ->placeholder('Από προϊόν ή ελεύθερο κείμενο'),

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
                                ->prefix('€')
                                ->live(onBlur: true)
                                // G7: typing net re-derives the gross mirror.
                                ->afterStateUpdated(fn ($state, callable $set, Get $get) => $set(
                                    'price_per_item_wvat',
                                    self::grossFromNet(self::numOrNull($state), self::numOrNull($get('vat_percent')))
                                )),

                            // G7: gross-price affordance — operator may type the
                            // VAT-inclusive unit price and we back-compute net
                            // (legacy GridPricesWVat / FAddInvoice2.cpp gross-edit
                            // path). Net price_per_item stays the stored source of
                            // truth; this field is NOT persisted (dehydrated false)
                            // — InvoiceLine::saving recomputes line totals from net.
                            TextInput::make('price_per_item_wvat')
                                ->label('Unit price (incl. VAT)')
                                ->numeric()
                                ->step('0.01')
                                ->minValue(0)
                                ->prefix('€')
                                ->dehydrated(false)
                                ->live(onBlur: true)
                                // Seed from the existing net price when editing a line.
                                ->afterStateHydrated(fn ($state, callable $set, Get $get) => $set(
                                    'price_per_item_wvat',
                                    self::grossFromNet(self::numOrNull($get('price_per_item')), self::numOrNull($get('vat_percent')))
                                ))
                                // Typing gross back-computes the stored net price.
                                ->afterStateUpdated(fn ($state, callable $set, Get $get) => $set(
                                    'price_per_item',
                                    self::netFromGross(self::numOrNull($state), self::numOrNull($get('vat_percent')))
                                )),

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
                                ->suffix('%')
                                ->live(onBlur: true)
                                // G7: changing the rate re-derives the gross mirror
                                // from the (unchanged) stored net price.
                                ->afterStateUpdated(fn ($state, callable $set, Get $get) => $set(
                                    'price_per_item_wvat',
                                    self::grossFromNet(self::numOrNull($get('price_per_item')), self::numOrNull($state))
                                )),

                            TextInput::make('notes')
                                ->label('Σημείωση γραμμής'),
                        ])
                        ->addActionLabel('+ Add line')
                        ->reorderable(false)
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),
                ]),

            // ─── Στοιχεία πελάτη (snapshot) — collapsed ───
            Section::make('Customer snapshot')
                ->description('Frozen at issue time. Auto-fills from the customer; you can override before save. Once filed at myDATA, the snapshot is legally locked.')
                ->collapsed()
                ->columns(2)
                ->disabled(fn ($record) => $record && $record->mydata_state !== null)
                ->schema([
                    TextInput::make('company_name')->label('Name on invoice')->columnSpanFull(),
                    TextInput::make('vat_no')->label('ΑΦΜ'),
                    TextInput::make('vies_vat')->label('VIES VAT'),
                    TextInput::make('occupation')->label('Δραστηριότητα'),
                    TextInput::make('address1')->label('Address')->columnSpanFull(),
                    TextInput::make('address2')->label('Address (line 2)')->columnSpanFull(),
                    TextInput::make('city'),
                    TextInput::make('postcode'),
                    TextInput::make('country')->maxLength(60)->helperText('ISO alpha-2 preferred. Normalised at submit time.'),
                ]),

            // ─── Σημειώσεις + παρακράτηση — collapsed ───
            Section::make('Σημειώσεις & παρακράτηση')
                ->collapsed()
                ->schema([
                    Textarea::make('notes')->rows(4)->columnSpanFull()->label('Internal / printed notes'),
                    TextInput::make('withhold_amount')
                        ->label('Withholding amount (€)')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->prefix('€')
                        ->live(onBlur: true)
                        ->helperText('Παρακράτηση φόρου — typically 20% on services. Subtracted from amount payable.'),

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
                        ->helperText('Υποχρεωτικό όταν υπάρχει ποσό παρακράτησης.'),
                ]),
        ]);
    }

    /**
     * G7 gross-price affordance. Convert between the NET unit price
     * (price_per_item — the stored source of truth) and the VAT-inclusive
     * unit price the operator may prefer to type. Mirrors the legacy
     * gross-edit path (FAddInvoice2.cpp):
     *
     *   price_per_item = price_per_item_wvat / (1 + vat/100)
     *
     * Rounded to 2dp to match the InvoiceLine decimal:2 columns. A null
     * (empty) input passes through as null so a blank field never shows a
     * spurious 0.
     */
    public static function grossFromNet(?float $net, ?float $vatPercent): ?float
    {
        return $net === null ? null : round($net * (1 + (float) $vatPercent / 100), 2);
    }

    public static function netFromGross(?float $gross, ?float $vatPercent): ?float
    {
        return $gross === null ? null : round($gross / (1 + (float) $vatPercent / 100), 2);
    }

    /** Normalise a Filament numeric-input value ('' / null → null) to float. */
    private static function numOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * Does reverse-charge (0% intra-community) apply for the given customer in
     * the current tenant? Used by the lines repeater to default a picked
     * product's VAT to 0%. Deterministic — true only for an EU-non-GR customer
     * AND a single configured 0% exemption category (see ReverseCharge).
     */
    private static function reverseChargeApplies(mixed $customerId): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant || ! $customerId) {
            return false;
        }
        $customer = Customer::find($customerId);

        return $customer !== null && ReverseCharge::shouldDefaultZeroVat($tenant, $customer);
    }
}
