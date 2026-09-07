<?php

namespace App\Filament\Resources\DomainTlds\Tables;

use App\Filament\Resources\DomainTlds\DomainTldResource;
use App\Filament\Support\GuardedDeleteAction;
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
            ->columns([
                TextColumn::make('tld')
                    ->label('TLD')
                    ->formatStateUsing(fn (string $state): string => '.'.$state)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('registrarConnection.registrar')
                    ->label('Registrar')
                    ->badge()
                    ->placeholder('— manual —')
                    ->formatStateUsing(fn (string $state): string => $registry->label($state)),

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
