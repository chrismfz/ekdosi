<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->label('Πελάτης')
                    ->options(fn () => Customer::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live(),

                Select::make('invoice_id')
                    ->label('Παραστατικό')
                    ->options(fn (Get $get) => $get('customer_id')
                        ? Invoice::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('customer_id', $get('customer_id'))
                            ->orderByDesc('issued_at')
                            ->pluck('invcode', 'id')
                        : [])
                    ->searchable()
                    ->placeholder('Έναντι λογαριασμού')
                    ->helperText('Κενό = έναντι λογαριασμού (πιστωτικό υπόλοιπο πελάτη, δεν εξοφλεί συγκεκριμένο παραστατικό).'),

                Select::make('payment_method_id')
                    ->label('Τρόπος πληρωμής')
                    ->options(fn () => PaymentMethod::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->pluck('description', 'id')),

                TextInput::make('amount')
                    ->label('Ποσό')
                    ->numeric()
                    ->required(),

                DatePicker::make('pay_date')
                    ->label('Ημερομηνία')
                    ->required()
                    ->default(now()),

                Textarea::make('notes')
                    ->label('Σημειώσεις')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
