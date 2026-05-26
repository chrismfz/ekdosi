<?php

namespace App\Filament\Resources\VatCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VatCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(120)
                    ->columnSpan(2)
                    ->helperText('Short label — e.g. "24% κανονικός", "13% μειωμένος", "0% απαλλαγή".'),

                TextInput::make('rate')
                    ->label('Rate')
                    ->required()
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->suffix('%'),

                Toggle::make('is_default')
                    ->label('Default for new products')
                    ->helperText('Only one default per tenant — saving with this on will demote any other default automatically.'),

                Textarea::make('long_description')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
