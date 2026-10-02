<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Filament\Support\Tags\TagControls;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Products\ProductMediaService;
use App\Services\Stock\StockService;
use App\Support\MyData\ClassificationGuidance;
use App\Support\Products\StockDisplay;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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

                // The ONE display rule (ProductMediaService::displayMedia): own photos, else —
                // for a variant — its colour's photos from the parent. Eager-loaded below.
                ImageColumn::make('photo')
                    ->label('Φωτογραφία')
                    ->state(fn (Product $record) => ProductMediaService::primaryImage($record)?->fileUrl('thumb'))
                    ->imageSize(40)
                    ->square()
                    ->toggleable(),

                TextColumn::make('sku')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('barcode')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('internal_code')
                    ->label('Εσωτ. κωδικός')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('description_short')
                    ->label('Description')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Product $record) => match ($record->kind) {
                        Product::KIND_VARIABLE => 'Με παραλλαγές · '.(int) ($record->variants_count ?? 0),
                        Product::KIND_VARIANT => 'Παραλλαγή',
                        default => null,
                    }),

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

                // MYD-006: how this item is classified to AADE (§8.6), from its
                // category. «τύπος/πολιτική» = inherited from the invoice type +
                // business policy at issue (the category set no explicit bucket).
                TextColumn::make('productCategory.mydata_income_class_category')
                    ->label('Κατηγ. εσόδων')
                    ->formatStateUsing(fn (?string $state) => $state
                        ? $state.' · '.(ClassificationGuidance::bucketLabel($state) ?? '')
                        : null)
                    ->placeholder('τύπος/πολιτική')
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
                    // A variable parent has no movements of its own — it shows its tracked
                    // variants' total (StockService::currentStock's definition), toned on the
                    // total alone: reorder thresholds are per sellable unit (the variants).
                    ->state(fn (Product $record) => match (true) {
                        $record->isVariable() => ($record->tracked_variants_count ?? 0) > 0 ? (float) ($record->variants_stock ?? 0) : null,
                        (bool) $record->track_stock => (float) ($record->stock_on_hand ?? 0),
                        default => null,
                    })
                    ->numeric(decimalPlaces: 3)
                    ->badge()
                    ->color(fn (Product $record) => $record->isVariable()
                        ? StockDisplay::tone(($record->tracked_variants_count ?? 0) > 0 ? (float) ($record->variants_stock ?? 0) : null)
                        : StockDisplay::tone(
                            $record->track_stock ? (float) ($record->stock_on_hand ?? 0) : null,
                            $record->reorder_level !== null ? (float) $record->reorder_level : null,
                        ))
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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['media', 'parent.media', 'variantValues'])
                ->withSum('stockMovements as stock_on_hand', 'qty_change')
                ->withCount(['variants', 'variants as tracked_variants_count' => fn (Builder $q) => $q->where('track_stock', true)])
                ->selectSub(fn ($q) => $q->from('stock_movements')
                    ->join('products as v', 'v.id', '=', 'stock_movements.product_id')
                    ->whereColumn('v.parent_product_id', 'products.id')
                    ->whereNull('v.deleted_at')
                    ->where('v.track_stock', true)
                    ->selectRaw('COALESCE(SUM(stock_movements.qty_change), 0)'), 'variants_stock'))
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->default(true)
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->placeholder('All'),

                // Variants (docs/woocommerce-bridge-plan.md §0): by default the list shows
                // simple products + variable parents; a search (barcode/SKU scan) always
                // reaches the variants too.
                SelectFilter::make('kind_view')
                    ->label('Παραλλαγές')
                    ->options([
                        'grouped' => 'Χωρίς παραλλαγές (γονικά + απλά)',
                        'variants' => 'Μόνο παραλλαγές',
                        'all' => 'Όλα',
                    ])
                    ->default('grouped')
                    ->query(function (Builder $query, array $data, $livewire): Builder {
                        $view = $data['value'] ?? 'grouped';
                        if ($view === 'variants') {
                            return $query->where('kind', Product::KIND_VARIANT);
                        }
                        // Searching, or asking for stock status (reorder is per variant),
                        // must reach the variants — otherwise «grouped» would hide them.
                        $stockStatus = data_get($livewire->tableFilters ?? [], 'stock_status.value');
                        if ($view === 'grouped' && blank($livewire->getTableSearch()) && blank($stockStatus)) {
                            return $query->where('kind', '!=', Product::KIND_VARIANT);
                        }

                        return $query;
                    }),

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
                        // WHERE on the correlated sum — no GROUP BY/HAVING: products.* under
                        // MariaDB's ONLY_FULL_GROUP_BY made the old groupBy+having a 1055.
                        // Reorder is per sellable unit — the variants, never their grouping parent.
                        $onHand = '(SELECT COALESCE(SUM(sm.qty_change), 0) FROM stock_movements sm WHERE sm.product_id = products.id)';
                        $query->where('products.track_stock', true)
                            ->where('products.kind', '!=', Product::KIND_VARIABLE);
                        if ($v === 'negative') {
                            return $query->whereRaw("{$onHand} < 0");
                        }

                        // low/out: ≤0, or ≤ reorder_level when a threshold is set.
                        return $query->whereRaw(
                            "({$onHand} <= 0 OR (products.reorder_level IS NOT NULL AND products.reorder_level > 0 AND {$onHand} <= products.reorder_level))"
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
                    ->visible(fn (Product $record) => $record->track_stock && ! $record->isVariable() && ! $record->trashed())
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
                    GuardedDeleteAction::bulk(fn (Product $record): array => ProductResource::dependents($record)),
                    // Restore skips variants whose parent is gone / whose axes changed
                    // (Product::restoring refuses them) and says how many were skipped.
                    RestoreBulkAction::make()
                        ->action(function (Collection $records): void {
                            // Parents before variants, so a parent restored in the same batch
                            // is already live when its variants are checked.
                            $restored = $records
                                ->sortBy(fn (Product $record) => $record->isVariant() ? 1 : 0)
                                ->filter(fn (Product $record) => $record->restore())
                                ->count();
                            $skipped = $records->count() - $restored;
                            Notification::make()
                                ->{$skipped > 0 ? 'warning' : 'success'}()
                                ->title('Επαναφέρθηκαν: '.$restored)
                                ->body($skipped > 0 ? 'Παραλείφθηκαν '.$skipped.' παραλλαγές (διαγραμμένο γονικό ή άλλα χαρακτηριστικά).' : null)
                                ->send();
                        }),
                    GuardedDeleteAction::forceBulk(fn (Product $record): array => ProductResource::forceDependents($record)),
                ]),
            ])
            ->defaultSort('description_short');
    }
}
