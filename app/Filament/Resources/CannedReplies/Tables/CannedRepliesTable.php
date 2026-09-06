<?php

namespace App\Filament\Resources\CannedReplies\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CannedRepliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Τίτλος')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Κατηγορία')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Ενεργή')
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
