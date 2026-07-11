<?php

namespace App\Filament\Resources\VatCategories\Tables;

use App\Filament\Resources\VatCategories\VatCategoryResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Support\MyData\Codes;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
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
                    ->sortable()
                    // Flag a rate AADE won't accept (§8.2 = 0/4/6/9/13/17/24).
                    // MyDataSubmitter::vatCategoryFor throws on anything else, so
                    // an invoice using this category would be rejected at filing.
                    ->color(fn ($record) => self::isAadeRate($record) ? null : 'danger')
                    ->tooltip(fn ($record) => self::isAadeRate($record)
                        ? null
                        : 'Μη έγκυρος συντελεστής ΑΑΔΕ (§8.2). Τα παραστατικά με αυτή την κατηγορία θα απορριφθούν στο myDATA.')
                    ->icon(fn ($record) => self::isAadeRate($record) ? null : 'heroicon-o-exclamation-triangle'),

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
                    GuardedDeleteAction::bulk(fn ($record): array => VatCategoryResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => VatCategoryResource::dependents($record)),
                ]),
            ])
            ->defaultSort('rate');
    }

    /**
     * Is this row fileable at AADE? Single source of truth in Codes. MYD-8: a
     * 3%/4% row is fileable when it carries a valid §8.2 override, so pass it.
     */
    private static function isAadeRate($record): bool
    {
        return Codes::vatRateFileable((float) $record->rate, $record->mydata_vat_category);
    }
}
