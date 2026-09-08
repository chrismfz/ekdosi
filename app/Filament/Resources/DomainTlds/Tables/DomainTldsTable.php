<?php

namespace App\Filament\Resources\DomainTlds\Tables;

use App\Filament\Resources\DomainTlds\DomainTldResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DomainTldsTable
{
    public static function configure(Table $table): Table
    {
        $registry = app(DomainRegistrarRegistry::class);

        return $table
            // The state() registrar column reads the relation per row — eager
            // load it (the dot-notation auto-eager-load went away with state()).
            ->modifyQueryUsing(fn ($query) => $query->with('registrarConnection:id,registrar,label'))
            ->columns([
                TextColumn::make('tld')
                    ->label('TLD')
                    ->formatStateUsing(fn (string $state): string => '.'.$state)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('registrar')
                    ->label('Registrar')
                    ->badge()
                    // THE shared label helper — a custom connection label (e.g.
                    // «Openprovider MyIP») renders the same on every surface.
                    ->state(fn (DomainTld $record): string => $registry->connectionLabel($record->registrarConnection)),

                TextColumn::make('min_years')
                    ->label('Ελάχ. έτη')
                    ->alignCenter(),

                TextColumn::make('prices_count')
                    ->label('Τιμές')
                    ->counts('prices')
                    ->alignCenter(),

                ToggleColumn::make('is_active')
                    ->label('Ενεργό'),

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
                GuardedDeleteAction::make(fn ($record): array => DomainTldResource::dependents($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteAction::bulk(fn ($record): array => DomainTldResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => DomainTldResource::dependents($record)),
                ]),
            ])
            ->defaultSort('tld');
    }
}
