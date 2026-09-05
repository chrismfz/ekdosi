<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Tables;

use App\Models\PaymentGatewayConnection;
use App\Services\Payments\PaymentGatewayRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PaymentGatewayConnectionsTable
{
    public static function configure(Table $table): Table
    {
        $registry = app(PaymentGatewayRegistry::class);

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('gateway')
                    ->label('Τύπος')
                    ->badge()
                    // label() (not for()) so a stale/removed key renders as its raw
                    // key, never a Null fallback + a per-row log warning.
                    ->formatStateUsing(fn (string $state): string => $registry->label($state)),

                TextColumn::make('label')
                    ->label('Όνομα (προς πελάτη)')
                    ->placeholder('— προεπιλογή τύπου —')
                    ->searchable(),

                // Inline enable/disable (WHMCS-style). The customer only ever sees active ones.
                ToggleColumn::make('is_active')
                    ->label('Ενεργό'),

                TextColumn::make('sort')
                    ->label('Σειρά')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('test')
                    ->label('Έλεγχος')
                    ->icon('heroicon-o-signal')
                    ->action(function (PaymentGatewayConnection $record) use ($registry): void {
                        $result = $registry->for($record->gateway)->testConnection($record);
                        $n = Notification::make()->title($result->message);
                        ($result->ok ? $n->success() : $n->danger())->send();
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
