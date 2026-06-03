<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\Tags\TagControls;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->color(function ($record) {
                        if (! $record->track_stock) {
                            return 'gray';
                        }
                        $s = (float) ($record->stock_on_hand ?? 0);
                        if ($s < 0) {
                            return 'danger';   // backorder
                        }
                        $reorder = (float) ($record->reorder_level ?? 0);
                        if ($s <= 0 || ($reorder > 0 && $s <= $reorder)) {
                            return 'warning';  // χαμηλό / εξαντλημένο → αναπαραγγελία
                        }

                        return 'success';
                    })
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

                SelectFilter::make('stock_status')
                    ->label('Κατάσταση αποθέματος')
                    ->options([
                        'low' => 'Χρειάζεται αναπαραγγελία (χαμηλό/εξαντλημένο)',
                        'negative' => 'Αρνητικό (backorder)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $v = $data['value'] ?? null;
                        if (! $v) {
                            return $query;
                        }
                        // groupBy the PK so HAVING on the withSum alias works on
                        // sqlite too (MySQL tolerates HAVING without GROUP BY).
                        $query->where('track_stock', true)->groupBy('products.id');
                        if ($v === 'negative') {
                            return $query->havingRaw('COALESCE(stock_on_hand, 0) < 0');
                        }

                        // low/out: ≤0, or ≤ reorder_level when a threshold is set.
                        return $query->havingRaw(
                            'COALESCE(stock_on_hand, 0) <= 0 OR (reorder_level IS NOT NULL AND reorder_level > 0 AND COALESCE(stock_on_hand, 0) <= reorder_level)'
                        );
                    }),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('receive_stock')
                    ->label('Παραλαβή')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->visible(fn (Product $record) => $record->track_stock && ! $record->trashed())
                    ->schema([
                        TextInput::make('qty')
                            ->label('Ποσότητα παραλαβής')
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                        Textarea::make('note')
                            ->label('Σημείωση')
                            ->rows(2),
                    ])
                    ->action(function (Product $record, array $data): void {
                        app(StockService::class)->record(
                            product: $record,
                            qtyChange: (float) $data['qty'],
                            reason: StockMovement::REASON_RECEIPT,
                            note: $data['note'] ?? null,
                        );
                        Notification::make()
                            ->success()
                            ->title('Παραλαβή καταχωρήθηκε: '.$record->description_short)
                            ->body('Νέο απόθεμα: '.rtrim(rtrim((string) app(StockService::class)->currentStock($record), '0'), '.'))
                            ->send();
                    }),

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
