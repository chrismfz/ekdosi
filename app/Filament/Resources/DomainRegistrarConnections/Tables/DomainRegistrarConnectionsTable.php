<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Tables;

use App\Filament\Resources\DomainRegistrarConnections\DomainRegistrarConnectionResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DomainRegistrarConnectionsTable
{
    public static function configure(Table $table): Table
    {
        $registry = app(DomainRegistrarRegistry::class);

        return $table
            ->columns([
                TextColumn::make('registrar')
                    ->label('Registrar')
                    ->badge()
                    // label() (not for()) so a stale/removed key renders as its raw
                    // key, never a Null fallback + a per-row log warning.
                    ->formatStateUsing(fn (string $state): string => $registry->label($state)),

                TextColumn::make('label')
                    ->label('Όνομα')
                    // «Κενό → όνομα registrar» — THE shared helper, no local copy.
                    ->state(fn (DomainRegistrarConnection $record): string => $registry->connectionLabel($record))
                    // Search must find what the column SHOWS: label OR (for a
                    // blank label) the registrar key its fallback name comes from.
                    ->searchable(query: fn ($query, string $search) => $query->where(
                        fn ($q) => $q->where('label', 'like', "%{$search}%")
                            ->orWhere('registrar', 'like', "%{$search}%")
                    )),

                TextColumn::make('mode')
                    ->label('Περιβάλλον')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'production' => 'Παραγωγή',
                        'sandbox' => 'Δοκιμαστικό',
                        default => 'Ανενεργό',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'production' => 'success',
                        'sandbox' => 'warning',
                        default => 'gray',
                    }),

                ToggleColumn::make('is_active')
                    ->label('Ενεργή'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('test')
                    ->label('Έλεγχος σύνδεσης')
                    ->icon('heroicon-o-signal')
                    ->action(function (DomainRegistrarConnection $record): void {
                        $factory = app(DomainRegistrarFactory::class);
                        try {
                            $ok = $factory->for($record)->ping($factory->credentialsFor($record));
                        } catch (DomainRegistrarNotConfigured $e) {
                            // The typed «no API / no creds» refusal real adapters
                            // throw — surface it, never a Livewire 500.
                            Notification::make()->title('Η σύνδεση δεν είναι ρυθμισμένη.')->body($e->getMessage())->danger()->send();

                            return;
                        } catch (\Throwable $e) {
                            // ping() SHOULD return false on transport failure (the
                            // contract), but a diagnostics button must never 500 on
                            // an adapter that lets a timeout escape.
                            Notification::make()->title('Ο έλεγχος απέτυχε.')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $n = $ok
                            ? Notification::make()->title('Η σύνδεση απαντά.')->success()
                            : Notification::make()
                                ->title('Καμία απόκριση από τον registrar.')
                                ->body('Ο «Manual» registrar δεν έχει API· για τους υπόλοιπους ελέγξτε adapter/credentials/mode.')
                                ->danger();
                        $n->send();
                    }),
                GuardedDeleteAction::make(fn ($record): array => DomainRegistrarConnectionResource::dependents($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteAction::bulk(fn ($record): array => DomainRegistrarConnectionResource::dependents($record)),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
