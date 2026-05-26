<?php

namespace App\Filament\Resources\MetricUnits\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class MetricUnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(15)
                    ->helperText('Short unit code — e.g. "ΤΕΜ", "ΚΙΛΟ", "ΜΕΤ", "ΤΚΥΛ".'),

                Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
