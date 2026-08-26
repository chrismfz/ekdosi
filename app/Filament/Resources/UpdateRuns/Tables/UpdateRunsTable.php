<?php

namespace App\Filament\Resources\UpdateRuns\Tables;

use App\Models\UpdateRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * History of application updates, newest first. Polls every 10s so a run in
 * flight (triggered from the SystemHealth page) surfaces here without an F5.
 */
class UpdateRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        UpdateRun::STATUS_SUCCEEDED => 'success',
                        UpdateRun::STATUS_FAILED => 'danger',
                        UpdateRun::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        UpdateRun::STATUS_QUEUED => 'σε αναμονή',
                        UpdateRun::STATUS_RUNNING => 'εκτελείται',
                        UpdateRun::STATUS_SUCCEEDED => 'ολοκληρώθηκε',
                        UpdateRun::STATUS_FAILED => 'απέτυχε',
                        UpdateRun::STATUS_ROLLED_BACK => 'επαναφορά',
                        default => $state,
                    }),

                TextColumn::make('kind')
                    ->label('Είδος')
                    ->badge()
                    ->color(fn (string $state): string => $state === UpdateRun::KIND_ROLLBACK ? 'warning' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === UpdateRun::KIND_ROLLBACK ? 'επαναφορά' : 'ενημέρωση'),

                TextColumn::make('phase')
                    ->label('Στάδιο')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('to_ref')
                    ->label('Προς')
                    ->placeholder('—'),

                TextColumn::make('from_ref')
                    ->label('Από')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('triggeredByUser.name')
                    ->label('Από χρήστη')
                    ->placeholder('Σύστημα')
                    ->toggleable(),

                TextColumn::make('started_at')
                    ->label('Έναρξη')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Λήξη')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
