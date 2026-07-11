<?php

namespace App\Filament\Resources\DeliveryMethods\Tables;

use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DeliveryMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('description')
                    ->searchable()
                    ->sortable(),

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
                    GuardedDeleteAction::bulk(fn ($record): array => DeliveryMethodResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => DeliveryMethodResource::dependents($record)),
                ]),
            ])
            ->defaultSort('description');
    }
}
