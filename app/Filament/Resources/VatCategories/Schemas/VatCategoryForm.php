<?php

namespace App\Filament\Resources\VatCategories\Schemas;

use App\Support\MyData\Codes;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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

                // G4: when the rate is 0%, AADE files it as vatCategory=7
                // (exempt) and REQUIRES a reason code (§8.3, 1–31). Set it here
                // so MyDataSubmitter can emit it. Only relevant for 0% rows.
                Select::make('vat_exemption_category')
                    ->label('Αιτία εξαίρεσης ΦΠΑ (για 0%)')
                    ->options(collect(Codes::VAT_EXEMPTION_CATEGORIES)
                        ->mapWithKeys(fn (int $c) => [$c => 'Κατηγορία '.$c])
                        ->all())
                    ->searchable()
                    ->visible(fn (Get $get) => (float) $get('rate') === 0.0)
                    ->required(fn (Get $get) => (float) $get('rate') === 0.0)
                    ->helperText('§8.3 ΑΑΔΕ: π.χ. ενδοκοινοτική παράδοση, εξαγωγή, άρθρο 39α. Υποχρεωτικό για συντελεστή 0% ώστε να υποβάλλονται τα παραστατικά.'),

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
