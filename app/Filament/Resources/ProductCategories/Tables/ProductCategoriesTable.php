<?php

namespace App\Filament\Resources\ProductCategories\Tables;

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('description_short')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('markup')
                    ->suffix('%')
                    ->alignRight()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteAction::bulk(fn ($record): array => ProductCategoryResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => ProductCategoryResource::dependents($record)),
                ]),
            ])
            ->defaultSort('description_short');
    }
}
