<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Expense lines, READ-ONLY display. The vatCategory / vatExemptionCategory
 * are the §8.2 / §8.3 codes stored verbatim from the supplier's doc.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Γραμμές';

    public function form(Schema $schema): Schema
    {
        // Required by the contract but unused — read-only.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('line_number')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('item_descr')
                    ->label('Περιγραφή')
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('quantity')
                    ->label('Ποσότητα')
                    ->numeric(decimalPlaces: 3)
                    ->placeholder('—')
                    ->alignRight(),

                TextColumn::make('net_value')
                    ->label('Καθαρή')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('vat_category')
                    ->label('Κατ. ΦΠΑ')
                    ->placeholder('—')
                    ->alignRight(),

                TextColumn::make('vat_exemption_category')
                    ->label('Απαλλαγή')
                    ->placeholder('—')
                    ->alignRight(),

                TextColumn::make('vat_amount')
                    ->label('ΦΠΑ')
                    ->money('EUR')
                    ->alignRight(),
            ])
            ->defaultSort('line_number');
    }
}
