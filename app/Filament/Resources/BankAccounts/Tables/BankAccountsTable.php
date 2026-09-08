<?php

namespace App\Filament\Resources\BankAccounts\Tables;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bank_name')
                    ->label('Τράπεζα')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('iban')
                    ->label('IBAN')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('account_name')
                    ->label('Δικαιούχος')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Ενεργός')
                    ->boolean(),

                IconColumn::make('show_on_invoices')
                    ->label('Στα τιμολόγια')
                    ->boolean(),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteAction::bulk(fn ($record): array => BankAccountResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => BankAccountResource::dependents($record)),
                ]),
            ])
            ->defaultSort('bank_name');
    }
}
