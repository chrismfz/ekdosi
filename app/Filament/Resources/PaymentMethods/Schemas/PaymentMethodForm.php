<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Support\MyData\Codes;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),

                TextInput::make('due_days')
                    ->label('Due days')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->helperText('0 = cash (does not count toward customer balance). >0 = credit terms; counted in customer balance.'),

                // G9: maps this method to an AADE §8.12 payment type so myDATA
                // filings carry the real type instead of always 3 (cash).
                Select::make('mydata_payment_type')
                    ->label('myDATA payment type (§8.12)')
                    ->options(Codes::PAYMENT_METHODS)
                    ->placeholder('— default: 3 (Μετρητά / cash) —')
                    ->helperText('Leave blank to file as cash (type 3). Set it for bank transfer / card / on-credit so the AADE payment type is correct.'),
            ]);
    }
}
