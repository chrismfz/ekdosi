<?php

namespace App\Filament\Resources\ProductCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description_short')
                    ->label('Name')
                    ->required()
                    ->maxLength(120)
                    ->columnSpan(2),

                TextInput::make('markup')
                    ->label('Default markup %')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->suffix('%')
                    ->helperText('Used as the default sell-price markup for new products in this category.'),

                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }
}
