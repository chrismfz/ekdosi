<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Enums\SupplierSource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Επωνυμία')
                    ->placeholder('— (χωρίς όνομα — άντληση από ΑΑΔΕ)')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('afm')
                    ->label('ΑΦΜ')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('city')
                    ->label('Πόλη')
                    ->toggleable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->limit(30)
                    ->tooltip(fn ($state): ?string => $state)
                    ->toggleable(),

                TextColumn::make('source')
                    ->label('Προέλευση')
                    ->badge()
                    ->formatStateUsing(fn (SupplierSource $state): string => $state->label())
                    ->color(fn (SupplierSource $state): string => match ($state) {
                        SupplierSource::Sync => 'info',
                        SupplierSource::Manual => 'gray',
                        SupplierSource::Import => 'warning',
                    })
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Ενεργός')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Δημιουργήθηκε')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ενεργός')
                    ->boolean()
                    ->default(true)
                    ->trueLabel('Μόνο ενεργοί')
                    ->falseLabel('Μόνο ανενεργοί')
                    ->placeholder('Όλοι'),

                SelectFilter::make('source')
                    ->label('Προέλευση')
                    ->options(SupplierSource::options()),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
