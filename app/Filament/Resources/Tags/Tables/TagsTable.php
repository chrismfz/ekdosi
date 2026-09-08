<?php

namespace App\Filament\Resources\Tags\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Όνομα')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                ColorColumn::make('color')
                    ->label('Χρώμα')
                    ->toggleable(),

                ToggleColumn::make('is_pinned')
                    ->label('Καρτέλα (fast filter)')
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Σειρά')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Δημιουργήθηκε')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_pinned')
                    ->label('Καρφιτσωμένες')
                    ->placeholder('Όλες'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
