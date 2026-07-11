<?php

namespace App\Filament\Resources\MetricUnits\Tables;

use App\Filament\Resources\MetricUnits\MetricUnitResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MetricUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('notes')
                    ->limit(60)
                    ->toggleable(),

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
                    GuardedDeleteAction::bulk(fn ($record): array => MetricUnitResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => MetricUnitResource::dependents($record)),
                ]),
            ])
            ->defaultSort('name');
    }
}
