<?php

namespace App\Filament\Resources\VatCategories\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class VatCategoriesTable
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

                TextColumn::make('rate')
                    ->suffix('%')
                    ->alignRight()
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_default')
                    ->label('Default only')
                    ->placeholder('All')
                    ->trueLabel('Default only')
                    ->falseLabel('Non-default only'),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                // One-click parity with the legacy ToolAssignDefault button
                // (FManageVatCategories.cpp:136). Saves through the model so
                // VatCategoryObserver demotes the previous default in the
                // same transaction.
                Action::make('set_as_default')
                    ->authorize('update')
                    ->label('Set as default')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn ($record) => ! $record->is_default && ! $record->trashed())
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $record->update(['is_default' => true]);
                        Notification::make()
                            ->title('Default VAT: '.$record->description.' ('.$record->rate.'%)')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('rate');
    }
}
