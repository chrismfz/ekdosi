<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Tables;

use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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
                    ->placeholder('— όνομα registrar —')
                    ->searchable(),

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
            ->recordActions([
                EditAction::make(),
                Action::make('test')
                    ->label('Έλεγχος σύνδεσης')
                    ->icon('heroicon-o-signal')
                    ->action(function (DomainRegistrarConnection $record): void {
                        $factory = app(DomainRegistrarFactory::class);
                        $ok = $factory->for($record)->ping($factory->credentialsFor($record));

                        $n = $ok
                            ? Notification::make()->title('Η σύνδεση απαντά.')->success()
                            : Notification::make()
                                ->title('Καμία απόκριση από τον registrar.')
                                ->body('Ο «Manual» registrar δεν έχει API· για τους υπόλοιπους ελέγξτε adapter/credentials/mode.')
                                ->danger();
                        $n->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
