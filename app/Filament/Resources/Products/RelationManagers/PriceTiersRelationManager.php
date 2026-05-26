<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Quantity-break tiers for a product (legacy PROD_PRICE_QTY).
 *
 * Reference data only — legacy NEVER auto-applied these at invoice-line
 * time (FAddInvoice.cpp:188-197 reads PRODUCT.SELL_PRICE directly). The
 * operator looked at the tier list as a hint and typed the per-line price
 * by hand. We preserve that behaviour; future IssueInvoice action should
 * surface tiers as a hint, not auto-apply.
 *
 * Each row: "at qty ≥ N units, price is VAL (or apply DISCOUNT_PERCENT)."
 */
class PriceTiersRelationManager extends RelationManager
{
    protected static string $relationship = 'priceTiers';

    protected static ?string $title = 'Quantity-break tiers';

    protected static ?string $recordTitleAttribute = 'qty';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('qty')
                    ->label('Minimum quantity')
                    ->required()
                    ->numeric()
                    ->step('0.001')
                    ->minValue(0.001)
                    ->helperText('Tier applies when invoice line quantity ≥ this.'),

                TextInput::make('value')
                    ->label('Tier price (net)')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->prefix('€')
                    ->helperText('Either set a flat tier price OR a discount %, not both.'),

                TextInput::make('discount_percent')
                    ->label('Discount %')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),
            ])
            ->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('qty')
                    ->label('Min qty')
                    ->numeric(decimalPlaces: 3)
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('value')
                    ->label('Tier price')
                    ->money('EUR')
                    ->alignRight()
                    ->placeholder('—'),

                TextColumn::make('discount_percent')
                    ->label('Discount')
                    ->suffix('%')
                    ->alignRight()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        // Stamp company_id on create so the row lands in
                        // the current tenant. The parent Product already
                        // belongs to this tenant; we mirror it.
                        $data['company_id'] = Filament::getTenant()?->getKey();
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('qty');
    }
}
