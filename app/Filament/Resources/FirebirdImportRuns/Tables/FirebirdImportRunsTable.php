<?php

namespace App\Filament\Resources\FirebirdImportRuns\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FirebirdImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source')
                    ->label('Πηγή')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'epsilon' => 'Epsilon JSON',
                        default => 'Firebird',
                    })
                    ->color(fn (?string $state): string => $state === 'epsilon' ? 'info' : 'gray'),

                TextColumn::make('file_name')
                    ->label('Backup file')
                    ->searchable()
                    ->wrap()
                    ->description(fn ($record) => self::humanBytes($record->file_size)),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed'    => 'danger',
                        'uploaded'  => 'gray',
                        'restoring' => 'warning',
                        'importing' => 'warning',
                        default     => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('uploadedByUser.name')
                    ->label('Uploaded by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('counts_json')
                    ->label('Imported')
                    ->placeholder('—')
                    ->formatStateUsing(function ($state) {
                        if (! is_array($state)) {
                            return '—';
                        }
                        $highlights = [];
                        foreach (['customers', 'products', 'invoices'] as $key) {
                            if (isset($state[$key])) {
                                $highlights[] = number_format($state[$key]).' '.$key;
                            }
                        }
                        return $highlights ? implode(' · ', $highlights) : '—';
                    })
                    ->toggleable(),

                TextColumn::make('failed_step')
                    ->label('Failed at')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'uploaded'  => 'Uploaded',
                        'restoring' => 'Restoring',
                        'importing' => 'Importing',
                        'completed' => 'Completed',
                        'failed'    => 'Failed',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // Auto-refresh while there are non-terminal runs in view.
            // Cheap: a single COUNT(*) every 5s; visible benefit is the
            // operator doesn't have to F5 to see "did it finish?".
            ->poll('5s');
    }

    private static function humanBytes(?int $bytes): string
    {
        return \App\Support\Bytes::forHumans($bytes, '');
    }
}
