<?php

namespace App\Filament\Resources\ServiceContracts\Schemas;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBillingPrice;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Service-contract create/edit form. Picking a product (optional) prefills the
 * billing cycle, amount, VAT%, invoice type + provisioning module from the
 * catalogue + its enabled billing-price matrix — but everything stays editable
 * (the contract is a SNAPSHOT, so a later catalogue edit never rewrites it).
 * When the operator changes the cycle, the matching enabled price for that
 * (product, cycle) is copied into `amount`.
 */
class ServiceContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('customer_id')
                        ->label('Πελάτης')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Customer::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                ->orWhere('afm', 'like', "%{$search}%"))
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [$c->id => $c->name.($c->afm ? ' — '.$c->afm : '')])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => optional(Customer::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->find($value))->name)
                        ->live()
                        // The customer's default series (e.g. our own company → the
                        // informal «ΕΣΩ») pre-fills the renewal type — never clobbers
                        // a type the operator already picked.
                        ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                            if (blank($state) || filled($get('invoice_type_id'))) {
                                return;
                            }
                            $type = Customer::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->find($state)
                                ?->usableDefaultInvoiceType();
                            if ($type !== null) {
                                $set('invoice_type_id', $type->id);
                            }
                        }),

                    Select::make('product_id')
                        ->label('Προϊόν/Υπηρεσία (προαιρετικό)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Product::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('description_short', 'like', "%{$search}%")
                            ->limit(50)
                            ->pluck('description_short', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => optional(Product::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->find($value))->description_short)
                        ->live()
                        // Prefill the snapshot fields from the product + its
                        // enabled billing-price matrix. Never blanks an
                        // operator's existing value if the product lacks it.
                        ->afterStateUpdated(function ($state, callable $set, Get $get) {
                            if (! $state) {
                                return;
                            }
                            $product = Product::with('vatCategory')->find($state);
                            if (! $product) {
                                return;
                            }
                            if ($product->description_short) {
                                $set('description', $product->description_short);
                            }
                            if ($product->vatCategory) {
                                $set('vat_percent', (float) $product->vatCategory->rate);
                            }
                            if ($product->provisioning_module) {
                                $set('provisioning_module', $product->provisioning_module);
                            }
                            if ($product->default_suspend_after_days !== null) {
                                $set('suspend_after_days', $product->default_suspend_after_days);
                            }
                            if ($product->default_terminate_after_days !== null) {
                                $set('terminate_after_days', $product->default_terminate_after_days);
                            }
                            // Default the cycle to the product's first enabled
                            // billing price + copy that price into amount.
                            $cycle = $get('billing_cycle');
                            $price = self::priceFor($state, $cycle);
                            if ($price === null) {
                                $first = ProductBillingPrice::query()
                                    ->where('product_id', $state)
                                    ->where('is_enabled', true)
                                    ->first();
                                if ($first) {
                                    $set('billing_cycle', $first->billing_cycle->value);
                                    $set('amount', (float) $first->price);
                                    $set('setup_fee', (float) $first->setup_fee);
                                }
                            } else {
                                $set('amount', $price);
                            }
                        }),

                    Select::make('billing_cycle')
                        ->label('Κύκλος χρέωσης')
                        ->required()
                        ->options(BillingCycle::options())
                        ->default(BillingCycle::Annual->value)
                        ->live()
                        // Picking a cycle copies that (product, cycle) enabled
                        // price into amount, when a product is selected.
                        ->afterStateUpdated(function ($state, callable $set, Get $get) {
                            $price = self::priceFor($get('product_id'), $state);
                            if ($price !== null) {
                                $set('amount', $price);
                            }
                        }),

                    Select::make('status')
                        ->label('Κατάσταση')
                        ->required()
                        ->options(ServiceContractStatus::options())
                        ->default(ServiceContractStatus::Pending->value),

                    TextInput::make('quantity')
                        ->label('Ποσότητα')
                        ->numeric()
                        ->step('0.001')
                        ->minValue(0.001)
                        ->default(1)
                        ->required()
                        ->helperText('Μονάδες της επαναλαμβανόμενης χρέωσης (συνήθως 1).'),

                    TextInput::make('amount')
                        ->label('Ποσό (καθαρό, ανά κύκλο)')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->default(0)
                        ->prefix('€')
                        ->required(),

                    TextInput::make('setup_fee')
                        ->label('Τέλος εγκατάστασης')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->default(0)
                        ->prefix('€'),

                    TextInput::make('vat_percent')
                        ->label('ΦΠΑ %')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->maxValue(99.99)
                        ->default(24)
                        ->suffix('%')
                        ->required(),

                    Select::make('invoice_type_id')
                        ->label('Τύπος παραστατικού ανανέωσης')
                        ->options(fn () => InvoiceType::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            // A recurring renewal is a monetary invoice — never a
                            // movement-only 9.x Δελτίο Αποστολής (MYD-003).
                            ->monetary()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->pickerLabel()])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->helperText('Απαιτείται για να εκδοθεί ανανέωση. Χωρίς αυτόν η έκδοση μπλοκάρει. Άτυπη σειρά (π.χ. «ΕΣΩ») = δική μας υπηρεσία: ανανεώνεται κανονικά, χωρίς myDATA και εκτός υπολοίπων.'),

                    Select::make('payment_method_id')
                        ->label('Τρόπος πληρωμής')
                        ->options(fn () => PaymentMethod::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('description')
                            ->pluck('description', 'id'))
                        ->searchable()
                        ->preload()
                        ->helperText('Επί πιστώσει (due_days>0) → η ανανέωση παραμένει οφειλή/ληξιπρόθεσμη. Κενό → ο τύπος παραστατικού ορίζει τον τρόπο.'),

                    TextInput::make('description')
                        ->label('Περιγραφή')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('Εμφανίζεται ως περιγραφή γραμμής στο παραστατικό ανανέωσης.'),
                ]),

            Section::make('Παροχή & χρονισμός')
                ->columnSpanFull()
                ->columns(2)
                ->collapsed()
                ->schema([
                    DatePicker::make('start_date')
                        ->label('Ημερομηνία έναρξης'),

                    DatePicker::make('next_due_date')
                        ->label('Επόμενη χρέωση')
                        ->helperText('Όταν φτάσει αυτή η ημερομηνία (και είναι Ενεργό) δημιουργείται πρόχειρο παραστατικό ανανέωσης.'),

                    DatePicker::make('end_date')
                        ->label('Ημερομηνία λήξης'),

                    Select::make('server_id')
                        ->label('Server (προαιρετικό)')
                        ->options(fn () => Server::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->preload(),

                    TextInput::make('domain')
                        ->label('Domain')
                        ->maxLength(190),

                    TextInput::make('provisioning_module')
                        ->label('Module παροχής')
                        ->maxLength(40)
                        ->default('none'),

                    TextInput::make('suspend_after_days')
                        ->label('Αναστολή μετά (ημέρες)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Override της προεπιλογής προϊόντος. Κενό = προεπιλογή/καμία ενέργεια.'),

                    TextInput::make('terminate_after_days')
                        ->label('Τερματισμός μετά (ημέρες)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Override της προεπιλογής προϊόντος. Κενό = προεπιλογή/καμία ενέργεια.'),

                    Select::make('dunning_enabled')
                        ->label('Αυτόματο dunning')
                        ->options([
                            '' => 'Κληρονομεί από προϊόν',
                            '1' => 'Ναι (ενεργό)',
                            '0' => 'Όχι (ανενεργό)',
                        ])
                        // Null = inherit the product's dunning_enabled flag; the
                        // '1'/'0' strings cast to the nullable boolean column.
                        ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? null : (bool) $state)
                        ->formatStateUsing(fn ($state) => $state === null ? '' : ($state ? '1' : '0'))
                        ->default('')
                        ->helperText('Κληρονομεί τον διακόπτη του προϊόντος, εκτός αν τον εξαναγκάσεις εδώ για αυτή τη σύμβαση.'),

                    Textarea::make('notes')
                        ->label('Σημειώσεις')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * The enabled price for a (product, cycle) pair, or null when there's no
     * product / cycle / matching enabled matrix row. Tenant-scoped via the
     * product's company (ProductBillingPrice carries company_id too).
     */
    private static function priceFor(mixed $productId, mixed $cycle): ?float
    {
        if (! $productId || ! $cycle) {
            return null;
        }
        $row = ProductBillingPrice::query()
            ->where('product_id', $productId)
            ->where('billing_cycle', $cycle)
            ->where('is_enabled', true)
            ->first();

        return $row ? (float) $row->price : null;
    }
}
