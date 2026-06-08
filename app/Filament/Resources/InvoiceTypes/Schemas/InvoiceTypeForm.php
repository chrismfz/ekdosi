<?php

namespace App\Filament\Resources\InvoiceTypes\Schemas;

use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\PaymentMethod;
use App\Support\MyData\InvoiceTypeClassSuggester;
use App\Support\MyDataOptions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class InvoiceTypeForm
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
                                TextInput::make('code')
                                    ->label('Series code')
                                    ->required()
                                    ->maxLength(6)
                                    // Unique per tenant. ignorable() lets edit-page saves
                                    // pass through when the value didn't change.
                                    ->rule(fn ($record) => Rule::unique('invoice_types', 'code')
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->ignore($record?->id))
                                    ->helperText('Short series identifier (e.g. "APY", "TPY", "SDEP"). Combined with the running counter to form the invcode ("APY423").'),

                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(120)
                                    ->columnSpan(2),

                                TextInput::make('invcount')
                                    ->label('Next ΑΑ (auto-managed)')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('Increments atomically each time an invoice of this type is issued. Editing it manually would break ΑΑ continuity; use the artisan probe if you really need to reset it.'),

                                Toggle::make('show_on_menu')
                                    ->default(true)
                                    ->helperText('Show this type in the "new invoice" picker.'),

                                Toggle::make('is_credit')
                                    ->label('Credit document (πιστωτικό)'),

                                Toggle::make('is_return')
                                    ->label('Return document'),
                            ])
                            ->columns(3),

                        Tab::make('myDATA')
                            ->schema([
                                Select::make('mydata_type')
                                    ->label('myDATA invoice type')
                                    ->options(MyDataOptions::invoiceTypes())
                                    ->searchable()
                                    ->preload()
                                    // When empty, surface a name-based suggestion
                                    // (display-only — the operator still picks it).
                                    ->helperText(function ($state, $get): string|HtmlString {
                                        $base = 'AADE classification code that determines how this series is filed at myDATA. e.g. "1.1" sales invoice, "2.1" service invoice, "11.2" ΑΠΥ.';
                                        if (filled($state)) {
                                            return $base;
                                        }
                                        $s = InvoiceTypeClassSuggester::suggest(
                                            (string) $get('name'),
                                            (bool) $get('is_credit'),
                                            (bool) $get('is_return'),
                                        );

                                        return $s
                                            ? new HtmlString('<span class="fi-color-warning-600">Προτεινόμενη βάσει ονόματος: <strong>'.e($s['code']).'</strong> — '.e($s['label']).'</span> · '.$base)
                                            : $base;
                                    })
                                    // One-click apply: sets the suggested §8.1 type AND back-fills
                                    // the income chain (only the empty fields) so the operator
                                    // doesn't re-pick by hand. Shown only while the type is empty
                                    // and the name yields a confident guess.
                                    ->hintAction(
                                        Action::make('applyTypeSuggestion')
                                            ->label(function ($get): ?string {
                                                $s = InvoiceTypeClassSuggester::suggest((string) $get('name'), (bool) $get('is_credit'), (bool) $get('is_return'));

                                                return $s ? 'Χρήση πρότασης: '.$s['code'] : null;
                                            })
                                            ->icon('heroicon-m-sparkles')
                                            ->visible(function ($state, $get): bool {
                                                return blank($state)
                                                    && InvoiceTypeClassSuggester::suggest((string) $get('name'), (bool) $get('is_credit'), (bool) $get('is_return')) !== null;
                                            })
                                            ->action(function ($get, $set): void {
                                                $s = InvoiceTypeClassSuggester::suggest((string) $get('name'), (bool) $get('is_credit'), (bool) $get('is_return'));
                                                if ($s === null) {
                                                    return;
                                                }
                                                $set('mydata_type', $s['code']);
                                                if ($s['income_class'] !== null && blank($get('mydata_income_class'))) {
                                                    $set('mydata_income_class', $s['income_class']);
                                                }
                                                if ($s['income_class_category'] !== null && blank($get('mydata_income_class_category'))) {
                                                    $set('mydata_income_class_category', $s['income_class_category']);
                                                }
                                            }),
                                    ),

                                Select::make('mydata_income_class')
                                    ->label('Income classification')
                                    ->options(MyDataOptions::incomeClassificationTypes())
                                    ->searchable()
                                    ->preload()
                                    ->helperText('AADE revenue line type. e.g. "E3_561_001" = revenue from goods sales, "E3_561_002" = revenue from services.'),

                                Select::make('mydata_income_class_category')
                                    ->label('Income classification category')
                                    ->options(MyDataOptions::incomeClassificationCategories())
                                    ->searchable()
                                    ->preload()
                                    ->helperText('AADE per-rate bucket. e.g. "category1_1" = sales of goods at 24% VAT.'),

                                // G5: goods παραστατικά carry a per-line quantity;
                                // service types ([205]) forbid it. OFF by default
                                // = the sandbox-validated service payload.
                                Toggle::make('mydata_requires_quantity')
                                    ->label('Goods type — send per-line quantity')
                                    ->helperText('Enable ONLY for goods (πώληση αγαθών) types. Services (1.1/2.1/11.2) must leave this OFF — AADE rejects a quantity on them ([205]).'),
                            ])
                            ->columns(3),

                        Tab::make('Defaults')
                            ->schema([
                                Select::make('payment_method_id')
                                    ->label('Default payment method')
                                    ->options(fn () => PaymentMethod::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('description')
                                        ->pluck('description', 'id'))
                                    ->searchable()
                                    ->preload(),

                                Select::make('delivery_method_id')
                                    ->label('Default delivery method')
                                    ->options(fn () => DeliveryMethod::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('description')
                                        ->pluck('description', 'id'))
                                    ->searchable()
                                    ->preload(),

                                Select::make('distribution_aim_id')
                                    ->label('Default distribution aim')
                                    ->options(fn () => DistributionAim::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('description')
                                        ->pluck('description', 'id'))
                                    ->searchable()
                                    ->preload(),

                                Select::make('default_customer_id')
                                    ->label('Default customer')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Customer::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->where('name', 'like', "%{$search}%")
                                        ->orderBy('name')
                                        ->limit(50)
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->getOptionLabelUsing(fn ($value) => Customer::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->whereKey($value)
                                        ->value('name'))
                                    ->helperText('Pre-selected when issuing this document type (typical use: a "cash sale" stand-in customer for ΑΠΥ).'),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
