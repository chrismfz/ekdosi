<?php

namespace App\Filament\Resources\ProductAttributes\Schemas;

use App\Models\ProductAttribute;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class ProductAttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->label('Όνομα')
                            ->required()
                            ->maxLength(60)
                            ->placeholder('π.χ. Χρώμα, Μέγεθος, Νούμερο παπουτσιών')
                            ->rule(fn (?ProductAttribute $record) => Rule::unique('product_attributes', 'name')
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->ignore($record?->getKey())),

                        Select::make('kind')
                            ->label('Τύπος')
                            ->options(ProductAttribute::KINDS)
                            ->default(ProductAttribute::KIND_OTHER)
                            ->required()
                            ->live()
                            ->helperText('Στο πλέγμα αποθέματος τα χρώματα γίνονται γραμμές και τα μεγέθη στήλες.'),

                        TextInput::make('sort')
                            ->label('Σειρά')
                            ->numeric()
                            ->default(0)
                            ->helperText('Με αυτή τη σειρά μπαίνουν οι τιμές στο όνομα της παραλλαγής (π.χ. πρώτα χρώμα, μετά μέγεθος).'),
                    ]),

                Repeater::make('values')
                    ->label('Τιμές')
                    ->relationship('values')
                    ->orderColumn('sort')
                    ->reorderable()
                    ->columnSpanFull()
                    ->columns(3)
                    ->addActionLabel('Προσθήκη τιμής')
                    ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + [
                        'company_id' => Filament::getTenant()?->getKey(),
                    ])
                    ->schema([
                        TextInput::make('value')
                            ->label('Τιμή')
                            ->required()
                            ->maxLength(60)
                            ->distinct()
                            ->placeholder('π.χ. Μαύρο, M, 42'),

                        TextInput::make('code')
                            ->label('Κωδικός για SKU')
                            ->maxLength(12)
                            ->placeholder('π.χ. BLK')
                            ->helperText('Κενό = αυτόματα από την τιμή (λατινικά).'),

                        ColorPicker::make('color_hex')
                            ->label('Χρώμα (swatch)')
                            ->visible(fn (Get $get): bool => $get('../../kind') === ProductAttribute::KIND_COLOR),
                    ]),
            ]);
    }
}
