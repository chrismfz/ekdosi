<?php

namespace App\Filament\Resources\TicketBlockedSenders\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketBlockedSendersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pattern')
                    ->label('Email / domain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Αιτιολογία')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Από')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Αποκλείστηκε')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
