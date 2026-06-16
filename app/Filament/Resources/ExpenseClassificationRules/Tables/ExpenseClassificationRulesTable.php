<?php

namespace App\Filament\Resources\ExpenseClassificationRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ExpenseClassificationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_afm')
                    ->label('Προμηθευτής (ΑΦΜ)')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('invoice_type')
                    ->label('Τύπος')
                    ->placeholder('Όλοι')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('classification_type')
                    ->label('Χαρακτηρισμός (E3)')
                    ->badge()
                    ->color('primary'),

                TextColumn::make('classification_category')
                    ->label('Κατηγορία')
                    ->badge()
                    ->color('info'),

                TextColumn::make('label')
                    ->label('Σημείωση')
                    ->toggleable()
                    ->wrap(),

                TextColumn::make('priority')
                    ->label('Προτ.')
                    ->numeric()
                    ->alignRight()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Ενεργός')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Ενεργοί')->default(true),
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
            ->defaultSort('supplier_afm');
    }
}
