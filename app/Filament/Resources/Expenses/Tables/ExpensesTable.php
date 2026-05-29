<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseSource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('issue_date')
                    ->label('Έκδοση')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('Προμηθευτής')
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->supplier_afm)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('invoice_type')
                    ->label('Τύπος')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('mydata_mark')
                    ->label('ΜΑΡΚ')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('net_total')
                    ->label('Καθαρή')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('vat_total')
                    ->label('ΦΠΑ')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('gross_total')
                    ->label('Σύνολο')
                    ->money('EUR')
                    ->alignRight()
                    ->weight('bold'),

                TextColumn::make('mydata_state')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'CANCELLED' ? 'danger' : 'success')
                    ->placeholder('—'),

                TextColumn::make('classification_state')
                    ->label('Χαρακτηρισμός')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'classified' ? 'Χαρακτηρισμένο' : 'Αχαρακτήριστο')
                    ->color(fn (?string $state): string => $state === 'classified' ? 'success' : 'gray'),

                TextColumn::make('source')
                    ->label('Προέλευση')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseSource $state): string => $state->label())
                    ->color(fn (ExpenseSource $state): string => $state === ExpenseSource::Sync ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Προέλευση')
                    ->options(ExpenseSource::options()),

                SelectFilter::make('mydata_state')
                    ->label('Κατάσταση myDATA')
                    ->options(['VALID' => 'VALID', 'CANCELLED' => 'CANCELLED']),

                SelectFilter::make('classification_state')
                    ->label('Χαρακτηρισμός')
                    ->options(['classified' => 'Χαρακτηρισμένο'])
                    ->query(fn ($query, array $data) => ($data['value'] ?? null) === 'classified'
                        ? $query->where('classification_state', 'classified')
                        : $query),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('issue_date', 'desc');
    }
}
