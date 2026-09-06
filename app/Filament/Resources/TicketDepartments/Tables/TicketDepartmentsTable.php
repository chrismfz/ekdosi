<?php

namespace App\Filament\Resources\TicketDepartments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketDepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Τμήμα')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('agents_count')
                    ->counts('agents')
                    ->label('Χειριστές')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('clients_only')
                    ->label('Μόνο πελάτες')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Ενεργό')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort');
    }
}
