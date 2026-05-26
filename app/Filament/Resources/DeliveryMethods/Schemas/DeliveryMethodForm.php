<?php

namespace App\Filament\Resources\DeliveryMethods\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DeliveryMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),
            ]);
    }
}
