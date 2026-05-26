<?php

namespace App\Filament\Resources\InvoiceTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class InvoiceTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('code')
                    ->label('Series')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('invcount')
                    ->label('Next ΑΑ')
                    ->numeric()
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('mydata_type')
                    ->label('myDATA')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('show_on_menu')
                    ->label('On menu')
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('is_credit')
                    ->label('Credit')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_return')
                    ->label('Return')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('show_on_menu')
                    ->label('On menu')
                    ->placeholder('All')
                    ->default(true),

                TernaryFilter::make('is_credit')
                    ->label('Credit documents')
                    ->placeholder('All'),

                TernaryFilter::make('is_return')
                    ->label('Return documents')
                    ->placeholder('All'),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }
}
