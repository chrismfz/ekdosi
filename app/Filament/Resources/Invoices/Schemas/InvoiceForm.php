<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Support\BankAccountField;
use App\Filament\Support\PickerOptions;
use App\Filament\Support\Tags\TagControls;
use App\Filament\Support\VatRateOptions;
use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\MetricUnit;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use Firebed\AadeMyData\Enums\FeesPercentCategory;
use Firebed\AadeMyData\Enums\OtherTaxesPercentCategory;
use Firebed\AadeMyData\Enums\StampCategory;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use App\Support\MyData\ReverseCharge;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
 *
 * 6. Pickers (Είδος / Πελάτης / Προϊόν) surface favourites first, then
 *    the most-used rows, before falling back to a normal search-on-type.
 *    See favourite*Options() / order*Search() below. The Είδος carries
 *    its configured defaults (Σκοπός διακίνησης / τρόπος πληρωμής /
 *    αποστολής) onto the header when picked, so e.g. ΤΙΜ/ΤΠΥ default to
 *    "Πώληση" once configured once.
 */
class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ─── Κεφαλίδα: τύπος, πελάτης, τρόποι, ημερομηνίες ───
            Section::make()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('invoice_type_id')
                        ->label('Είδος Παραστατικού')
                        ->required()
                        ->options(fn () => PickerOptions::invoiceTypeOptions())
                        // Resolve the selected label WITHOUT the show_on_menu
                        // filter — so editing a draft whose type is hidden from
                        // the menu still renders its label (and survives save)
                        // instead of going blank. Mirrors customer/product.
                        ->getOptionLabelUsing(function ($value) {
                            $type = InvoiceType::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->find($value);

                            return $type ? $type->code.' — '.$type->name : null;
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        // Pre-fill the header dimensions configured on the
                        // chosen type (Σκοπός διακίνησης / τρόπος πληρωμής /
                        // αποστολής). Covers "ΤΙΜ/ΤΠΥ should default to Πώληση"
                        // without a hardcoded global default — set it once on
                        // the type. Only writes the fields the type actually
                        // configures; never blanks an operator's choice.
                        ->afterStateUpdated(function ($state, callable $set) {
                            if (! $state) {
                                return;
                            }
                            $type = InvoiceType::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->find($state);
                            if (! $type) {
                                return;
                            }
                            if ($type->distribution_aim_id) {
                                $set('distribution_aim_id', $type->distribution_aim_id);
                            }
                            if ($type->payment_method_id) {
                                $set('payment_method_id', $type->payment_method_id);
                            }
                            if ($type->delivery_method_id) {
                                $set('delivery_method_id', $type->delivery_method_id);
                            }
                        })
                        // Once issued (mydata_state set) the type is frozen — operator
                        // can't reclassify a filed invoice.
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                    DateTimePicker::make('issued_at')
                        ->label('Ημερομηνία έκδοσης')
                        ->required()
                        ->default(now())
                        ->seconds(false)
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                    Select::make('customer_id')
                        ->label('Πελάτης')
                        ->required()
                        ->searchable()
                        // Favourites + most-billed shown on OPEN (before typing);
                        // typing falls through to the search closure below.
                        ->options(fn () => PickerOptions::favouriteCustomerOptions())
                        ->getSearchResultsUsing(fn (string $search) => PickerOptions::searchCustomerOptions($search))
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
                        ->label('Τρόπος πληρωμής')
                        ->options(fn () => PaymentMethod::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('description')
                            ->pluck('description', 'id'))
                        ->searchable()
                        ->preload(),

                    BankAccountField::make(
                        Filament::getTenant()?->getKey(),
                        'Λογαριασμός κατάθεσης — τυπώνεται στο παραστατικό για πληρωμή με έμβασμα.',
                    ),

                    Select::make('delivery_method_id')
                        ->label('Τρόπος αποστολής')
                        ->options(fn () => DeliveryMethod::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('description')
                            ->pluck('description', 'id'))
                        ->searchable()
                        ->preload(),

                    Select::make('distribution_aim_id')
                        ->label('Σκοπός διακίνησης')
                        ->options(fn () => DistributionAim::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('description')
                            ->pluck('description', 'id'))
                        ->searchable()
                        ->preload(),

                    DatePicker::make('delivery_date')
                        ->label('Ημερομηνία παράδοσης'),

                    TextInput::make('header_discount_percent')
                        ->label('Έκπτωση παραστατικού %')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->maxValue(99.99)
                        ->default(0)
                        ->suffix('%')
                        ->helperText('Εφαρμόζεται σε όλες τις γραμμές. Πρέπει να είναι < 100.'),

                    TagControls::field()
                        ->columnSpanFull(),
                ]),

            // ─── Γραμμές (Excel-style) ───
            Section::make('Γραμμές')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->relationship('lines')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Προϊόν')->width('20%'),
                            TableColumn::make('Περιγραφή')->width('26%'),
                            TableColumn::make('Ποσότ.')->width('8%'),
                            TableColumn::make('Μ.Μ.')->width('8%'),
                            TableColumn::make('Τιμή (καθ.)')->width('11%'),
                            TableColumn::make('Τιμή (με ΦΠΑ)')->width('11%'),
                            TableColumn::make('Έκπτ.%')->width('8%'),
                            TableColumn::make('ΦΠΑ%')->width('8%'),
                        ])
                        ->schema([
                            Select::make('product_id')
                                ->label('Προϊόν/Υπηρεσία')
                                ->searchable()
                                // Favourites + most-sold shown on OPEN; typing
                                // falls through to the search closure.
                                ->options(fn () => PickerOptions::favouriteProductOptions())
                                ->getSearchResultsUsing(fn (string $search) => PickerOptions::searchProductOptions($search))
                                ->getOptionLabelUsing(fn ($value) => optional(Product::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->find($value))->description_short)
                                // Inline-create: make a product/service that
                                // doesn't exist yet without leaving the invoice.
                                // createOption fires afterStateUpdated (Filament
                                // Select.php:269-270), so the new row's price/VAT
                                // auto-fill just like picking an existing product.
                                ->createOptionForm(static::inlineProductForm())
                                ->createOptionUsing(fn (array $data) => static::createInlineProduct($data))
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
                                    // Normalised so the value matches a VAT-rate Select option.
                                    $set('vat_percent', VatRateOptions::normalize($vat));
                                    // G7: keep the VAT-inclusive mirror in sync.
                                    $set('price_per_item_wvat', self::grossFromNet($net, $vat));
                                    $set('metric_unit', $product->metricUnit?->name);
                                }),

                            TextInput::make('product_descr')
                                ->label('Περιγραφή')
                                ->placeholder('Από προϊόν ή ελεύθερο κείμενο'),

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
                                ->label('Τιμή (καθαρή)')
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
                                ->label('Τιμή (με ΦΠΑ)')
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
                                ->label('Έκπτωση %')
                                ->numeric()
                                ->step('0.0001')
                                ->minValue(0)
                                ->maxValue(100)
                                ->default(0)
                                ->suffix('%'),

                            Select::make('vat_percent')
                                ->label('ΦΠΑ %')
                                ->required()
                                ->options(fn () => VatRateOptions::options())
                                ->default(VatRateOptions::normalize(24))
                                ->selectablePlaceholder(false)
                                ->live()
                                // G7: changing the rate re-derives the gross mirror
                                // from the (unchanged) stored net price.
                                ->afterStateUpdated(fn ($state, callable $set, Get $get) => $set(
                                    'price_per_item_wvat',
                                    self::grossFromNet(self::numOrNull($get('price_per_item')), self::numOrNull($state))
                                )),

                            TextInput::make('notes')
                                ->label('Σημείωση γραμμής'),
                        ])
                        ->addActionLabel('+ Προσθήκη γραμμής')
                        ->reorderable(false)
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),
                ]),

            // ─── Στοιχεία πελάτη (snapshot) — collapsed ───
            Section::make('Στοιχεία πελάτη (στιγμιότυπο)')
                ->columnSpanFull()
                ->description('Παγώνουν κατά την έκδοση. Συμπληρώνονται αυτόματα από τον πελάτη· μπορείτε να τα αλλάξετε πριν την αποθήκευση. Μετά την υποβολή στο myDATA κλειδώνουν νομικά.')
                ->collapsed()
                ->columns(2)
                ->disabled(fn ($record) => $record && $record->mydata_state !== null)
                ->schema([
                    TextInput::make('company_name')->label('Επωνυμία στο παραστατικό')->columnSpanFull(),
                    TextInput::make('vat_no')->label('ΑΦΜ'),
                    TextInput::make('vies_vat')->label('ΦΠΑ VIES'),
                    TextInput::make('occupation')->label('Δραστηριότητα'),
                    TextInput::make('address1')->label('Διεύθυνση')->columnSpanFull(),
                    TextInput::make('address2')->label('Διεύθυνση (γραμμή 2)')->columnSpanFull(),
                    TextInput::make('city')->label('Πόλη'),
                    TextInput::make('postcode')->label('Τ.Κ.'),
                    TextInput::make('country')->label('Χώρα')->maxLength(60)->helperText('Κατά προτίμηση ISO alpha-2. Κανονικοποιείται κατά την υποβολή.'),
                ]),

            // ─── Παρατηρήσεις (εκτύπωσης) + παρακράτηση — collapsed ───
            Section::make('Παρατηρήσεις (εκτύπωσης) & παρακράτηση')
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    Textarea::make('notes')->rows(4)->columnSpanFull()
                        ->label('Παρατηρήσεις (εκτυπώνονται στο παραστατικό)')
                        ->helperText('⚠ Εμφανίζονται στο PDF και στο email του πελάτη. Για εσωτερικά σχόλια (π.χ. «κακοπληρωτής») χρησιμοποίησε την καρτέλα «Σημειώσεις (εσωτερικές)».'),
                    TextInput::make('withhold_amount')
                        ->label('Ποσό παρακράτησης (€)')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->prefix('€')
                        ->live(onBlur: true)
                        ->helperText('Παρακράτηση φόρου — συνήθως 20% στις υπηρεσίες. Αφαιρείται από το πληρωτέο ποσό.'),

                    // G1: AADE needs the withholding CATEGORY (§8.4) to
                    // file the taxesTotals block. Required whenever an
                    // amount is set; depends on the service (fees 20%,
                    // technicians 4/10%, lawyers 15%, …).
                    Select::make('withhold_category')
                        ->label('Κατηγορία παρακράτησης (myDATA §8.4)')
                        ->options(collect(Codes::WITHHOLDING_CATEGORIES)
                            ->mapWithKeys(fn (int $c) => [$c => $c.' — '.(WithheldPercentCategory::tryFrom($c)?->label() ?? 'Κατηγορία '.$c)])
                            ->all())
                        ->searchable()
                        ->required(fn (Get $get) => (float) ($get('withhold_amount') ?? 0) > 0)
                        ->helperText('Υποχρεωτικό όταν υπάρχει ποσό παρακράτησης.'),

                    // #3c: the other taxesTotals taxTypes (fees/otherTaxes/stamp/
                    // deductions). Each amount, when > 0, files a taxesTotals block +
                    // sets its summary total; the category is then required. Rare for
                    // service tenants — left blank, standard invoices are unaffected.
                    TextInput::make('fees_amount')
                        ->label('Τέλη — ποσό (€)')->numeric()->step('0.01')->minValue(0)->prefix('€')->live(onBlur: true)
                        ->helperText('π.χ. τέλος ανθεκτικότητας/διαμονής (myDATA taxType 2).'),
                    Select::make('fees_category')
                        ->label('Κατηγορία τελών (§8.5)')
                        ->options(collect(FeesPercentCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value.' — '.$c->label()])->all())
                        ->searchable()
                        ->required(fn (Get $get) => (float) ($get('fees_amount') ?? 0) > 0),

                    TextInput::make('other_taxes_amount')
                        ->label('Λοιποί φόροι — ποσό (€)')->numeric()->step('0.01')->minValue(0)->prefix('€')->live(onBlur: true)
                        ->helperText('myDATA taxType 3.'),
                    Select::make('other_taxes_category')
                        ->label('Κατηγορία λοιπών φόρων (§8.6)')
                        ->options(collect(OtherTaxesPercentCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value.' — '.$c->label()])->all())
                        ->searchable()
                        ->required(fn (Get $get) => (float) ($get('other_taxes_amount') ?? 0) > 0),

                    TextInput::make('stamp_duty_amount')
                        ->label('Χαρτόσημο — ποσό (€)')->numeric()->step('0.01')->minValue(0)->prefix('€')->live(onBlur: true)
                        ->helperText('myDATA taxType 4.'),
                    Select::make('stamp_duty_category')
                        ->label('Κατηγορία χαρτοσήμου (§8.7)')
                        ->options(collect(StampCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value.' — '.$c->label()])->all())
                        ->searchable()
                        ->required(fn (Get $get) => (float) ($get('stamp_duty_amount') ?? 0) > 0),

                    TextInput::make('deductions_amount')
                        ->label('Κρατήσεις — ποσό (€)')->numeric()->step('0.01')->minValue(0)->prefix('€')->live(onBlur: true)
                        ->helperText('myDATA taxType 5.'),
                    TextInput::make('deductions_category')
                        ->label('Κατηγορία κρατήσεων (§8.8)')->numeric()->minValue(1)
                        ->required(fn (Get $get) => (float) ($get('deductions_amount') ?? 0) > 0)
                        ->helperText('Κωδικός §8.8 (δεν υπάρχει enum στη βιβλιοθήκη — εισάγετε τον αριθμό).'),
                ]),
        ]);
    }

    /* ===================== Inline product create ===================== */

    /**
     * Minimal "create a product/service on the fly" form for the line
     * picker — just enough to bill it now (full catalogue fields live in
     * the Products resource). Net price + VAT category so the new line
     * fills correctly via the product_id afterStateUpdated.
     *
     * @return array<int, Field>
     */
    public static function inlineProductForm(): array
    {
        $tenant = fn () => Filament::getTenant()?->getKey();

        return [
            TextInput::make('description_short')
                ->label('Περιγραφή')
                ->required()
                ->maxLength(120),

            Select::make('product_category_id')
                ->label('Κατηγορία')
                ->required()
                ->options(fn () => ProductCategory::query()
                    ->where('company_id', $tenant())
                    ->orderBy('description_short')
                    ->pluck('description_short', 'id'))
                ->default(fn () => ProductCategory::query()
                    ->where('company_id', $tenant())
                    ->orderBy('description_short')
                    ->value('id'))
                ->searchable()
                ->preload(),

            Select::make('vat_category_id')
                ->label('Κατηγορία ΦΠΑ')
                ->required()
                ->options(fn () => VatCategory::query()
                    ->where('company_id', $tenant())
                    ->orderBy('rate')
                    ->get()
                    ->mapWithKeys(fn ($vc) => [$vc->id => $vc->description.' ('.$vc->rate.'%)'])
                    ->toArray())
                ->default(fn () => VatCategory::query()
                    ->where('company_id', $tenant())
                    ->where('is_default', true)
                    ->value('id'))
                ->searchable()
                ->preload(),

            TextInput::make('sell_price')
                ->label('Τιμή (καθαρή)')
                ->numeric()
                ->step('0.01')
                ->minValue(0)
                ->default(0)
                ->prefix('€'),

            Select::make('metric_unit_id')
                ->label('Μ.Μ.')
                ->options(fn () => MetricUnit::query()
                    ->where('company_id', $tenant())
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->preload(),
        ];
    }

    /**
     * Persist an inline-created product to the catalogue and return its
     * key (Filament selects it + fires the line's afterStateUpdated).
     * price_wvat is denormalised here the same way the Products form does.
     */
    public static function createInlineProduct(array $data): int
    {
        $companyId = Filament::getTenant()?->getKey();

        $rate = (float) (VatCategory::query()
            ->where('company_id', $companyId)
            ->whereKey($data['vat_category_id'] ?? null)
            ->value('rate') ?? 0);

        $sell = (float) ($data['sell_price'] ?? 0);

        $product = Product::create([
            'company_id' => $companyId,
            'description_short' => $data['description_short'],
            'product_category_id' => $data['product_category_id'] ?? null,
            'vat_category_id' => $data['vat_category_id'] ?? null,
            'metric_unit_id' => $data['metric_unit_id'] ?? null,
            'sell_price' => $sell,
            'price_wvat' => round($sell * (1 + $rate / 100), 2),
            'is_active' => true,
        ]);

        return $product->getKey();
    }

    /* ===================== Numeric / VAT helpers ===================== */

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
