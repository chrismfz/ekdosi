<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Resources\Companies\Actions\CompanyBackupActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'GR' => 'info',
                        'EE' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('einvoice_provider')
                    ->label('e-invoice')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'gr-mydata' => 'myDATA',
                        'ee-peppol' => 'PEPPOL',
                        'none' => 'PDF only',
                        default => $state,
                    }),
                TextColumn::make('mydata_mode')
                    ->label('myDATA')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'production' => 'danger',  // 🔴 LIVE — red
                        'sandbox' => 'warning',    // 🟡 test — yellow
                        'off' => 'gray',           // ⚪ off — gray
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'production' => 'LIVE',
                        'sandbox' => 'sandbox',
                        'off' => 'off',
                        default => '—',
                    }),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->alignRight()
                    ->numeric(),
                TextColumn::make('afm')
                    ->label('AFM')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options([
                        'GR' => 'Greece',
                        'EE' => 'Estonia',
                    ]),
                SelectFilter::make('einvoice_provider')
                    ->label('e-invoice provider')
                    ->options([
                        'gr-mydata' => 'Greek myDATA',
                        'ee-peppol' => 'Estonian PEPPOL',
                        'none' => 'None',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    CompanyBackupActions::export(),
                    CompanyBackupActions::importInto(),
                ])
                    ->label('Αντίγραφα')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->button(),
            ])
            ->toolbarActions([
                CompanyBackupActions::importNew(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
