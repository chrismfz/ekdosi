<?php

namespace App\Filament\Resources\ProductAttributes\Tables;

use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\ProductAttribute;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductAttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Όνομα')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Τύπος')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ProductAttribute::KINDS[$state] ?? $state),

                TextColumn::make('values.value')
                    ->label('Τιμές')
                    ->badge()
                    ->limitList(12)
                    ->expandableLimitedList(),

                TextColumn::make('sort')
                    ->label('Σειρά')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteAction::bulk(fn ($record): array => ProductAttributeResource::dependents($record)),
                ]),
            ])
            ->defaultSort('sort');
    }
}
