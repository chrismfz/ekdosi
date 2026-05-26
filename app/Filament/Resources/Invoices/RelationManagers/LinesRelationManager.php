<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Invoice lines, READ-ONLY display. No create/edit/delete actions —
 * mutating an invoice's lines after issuance is forbidden by myDATA
 * rules (frozen on MARK). Even DRAFT invoices' line editing lands in
 * PR #8 alongside the IssueInvoice form, not here.
 *
 * The `product_descr` and `metric_unit` columns are the SNAPSHOTS at
 * issue time — joining through the product relation would show the
 * current product description which may have changed since.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    protected static ?string $recordTitleAttribute = 'product_descr';

    public function form(Schema $schema): Schema
    {
        // Required by the RelationManager contract but unused — the
        // resource is read-only at this stage.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('product_descr')
                    ->label('Description')
                    ->wrap(),

                TextColumn::make('qty')
                    ->numeric(decimalPlaces: 3)
                    ->alignRight(),

                TextColumn::make('metric_unit')
                    ->label('Unit')
                    ->placeholder('—'),

                TextColumn::make('price_per_item')
                    ->label('Unit price')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('discount')
                    ->numeric(decimalPlaces: 4)
                    ->alignRight()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('vat_percent')
                    ->label('VAT')
                    ->suffix('%')
                    ->alignRight(),

                TextColumn::make('net_price')
                    ->label('Net')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('gross_price')
                    ->label('Gross')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // No row / bulk / header actions — read-only.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id');
    }
}
