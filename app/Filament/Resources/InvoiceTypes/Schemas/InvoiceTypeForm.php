<?php

namespace App\Filament\Resources\InvoiceTypes\Schemas;

use App\Models\Customer;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\PaymentMethod;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
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
                                TextInput::make('mydata_type')
                                    ->label('myDATA invoice type')
                                    ->maxLength(5)
                                    ->helperText('AADE invoice type code, e.g. "1.1", "2.1", "5.1". See firebed/aade-mydata InvoiceType enum.'),

                                TextInput::make('mydata_income_class')
                                    ->label('Income classification')
                                    ->maxLength(30)
                                    ->helperText('e.g. "E3_561_001" (revenue from goods sales).'),

                                TextInput::make('mydata_income_class_category')
                                    ->label('Income classification category')
                                    ->maxLength(30)
                                    ->helperText('e.g. "category1_1" (sales of goods, 24% VAT).'),
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
