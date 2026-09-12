<?php

namespace App\Filament\Resources\DeliveryNotes\Tables;

use App\Services\Delivery\DeliveryLifecycleService;
use App\Support\MyData\DeliveryCodes;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('invcode')
                    ->label('Κωδικός')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('issued_at')
                    ->label('Έκδοση')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('recipient_name')
                    ->label('Παραλήπτης')
                    ->placeholder('— (ενδοδιακίνηση)')
                    ->searchable(),

                TextColumn::make('move_purpose')
                    ->label('Σκοπός')
                    ->formatStateUsing(fn ($state) => DeliveryCodes::movePurposeLabel($state === null ? null : (int) $state))
                    ->placeholder('—'),

                TextColumn::make('local_status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Πρόχειρο',
                        'active' => 'Ενεργό',
                        'cancelled' => 'Ακυρωμένο',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'active' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('mydata_state')
                    ->label('myDATA')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        'VALID' => 'success',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('delivery_state')
                    ->label('Διακίνηση')
                    // Render the central Greek label (so e.g. 'in_transit_return'
                    // shows «Σε διακίνηση (επιστροφή)», not the raw snake_case).
                    ->formatStateUsing(fn (?string $state) => DeliveryLifecycleService::stateLabel($state) ?? '—')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('local_status')
                    ->label('Κατάσταση')
                    ->options([
                        'draft' => 'Πρόχειρο',
                        'active' => 'Ενεργό',
                        'cancelled' => 'Ακυρωμένο',
                    ]),
            ]);
    }
}
