<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\Tags\TagControls;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sku')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('barcode')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('description_short')
                    ->label('Description')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                // Pin frequent products/services to the top of the
                // invoice-line picker. Toggle inline.
                ToggleColumn::make('is_favorite')
                    ->label('Αγαπημένο')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('productCategory.description_short')
                    ->label('Category')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('sell_price')
                    ->label('Sell (net)')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('price_wvat')
                    ->label('Sell (gross)')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('vatCategory.rate')
                    ->label('VAT')
                    ->suffix('%')
                    ->alignRight()
                    ->toggleable(),

                TextColumn::make('stock_on_hand')
                    ->label('Απόθεμα')
                    // Real on-hand from the stock ledger (SUM of movements).
                    // Only meaningful for track_stock products; others show «—».
                    ->state(fn ($record) => $record->track_stock ? (float) ($record->stock_on_hand ?? 0) : null)
                    ->numeric(decimalPlaces: 3)
                    ->badge()
                    ->color(fn ($record) => $record->track_stock && (float) ($record->stock_on_hand ?? 0) < 0 ? 'danger' : 'gray')
                    ->placeholder('—')
                    ->alignRight()
                    ->toggleable(),

                TextColumn::make('reserve')
                    ->label('Reserve (legacy)')
                    ->numeric(decimalPlaces: 3)
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TagControls::column(),

                TextColumn::make('supplier')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('whmcs_product_id')
                    ->label('WHMCS')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('stockMovements as stock_on_hand', 'qty_change'))
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->default(true)
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->placeholder('All'),

                TernaryFilter::make('is_favorite')
                    ->label('Αγαπημένα')
                    ->placeholder('Όλα'),

                TagControls::filter(),

                SelectFilter::make('product_category_id')
                    ->label('Category')
                    ->relationship('productCategory', 'description_short')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('vat_category_id')
                    ->label('VAT')
                    ->relationship('vatCategory', 'description')
                    ->preload(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('toggle_active')
                    ->authorize('update')
                    ->label(fn ($record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn ($record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->is_active ? 'gray' : 'success')
                    ->visible(fn ($record) => ! $record->trashed())
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()
                            ->title(($record->is_active ? 'Activated: ' : 'Deactivated: ').$record->description_short)
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
            ->defaultSort('description_short');
    }
}
