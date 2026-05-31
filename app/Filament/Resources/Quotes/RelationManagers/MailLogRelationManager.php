<?php

namespace App\Filament\Resources\Quotes\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Mail send history for a quote — twin of the invoice MailLogRelationManager.
 * Read-only; rows are written by the (future) quote-email job's lifecycle.
 */
class MailLogRelationManager extends RelationManager
{
    protected static string $relationship = 'mailLog';

    protected static ?string $title = 'Ιστορικό αποστολών';

    protected static ?string $recordTitleAttribute = 'subject';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'sending' => 'info',
                        'queued' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('trigger')
                    ->label('Έναυσμα')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'auto' ? 'info' : 'warning')
                    ->formatStateUsing(fn (?string $state) => $state === 'auto' ? 'αυτόματο' : 'χειροκίνητο'),

                TextColumn::make('recipient')
                    ->label('Προς')
                    ->copyable()
                    ->limit(30),

                TextColumn::make('subject')
                    ->label('Θέμα')
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('queued_at')
                    ->label('Σε ουρά')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—'),

                TextColumn::make('sent_at')
                    ->label('Εστάλη')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—'),

                TextColumn::make('failed_at')
                    ->label('Απέτυχε')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('triggeredByUser.name')
                    ->label('Από')
                    ->placeholder('σύστημα')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view_error')
                    ->label('Σφάλμα')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn ($record) => ! empty($record->error_message))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Κλείσιμο')
                    ->schema(fn ($record) => [
                        Textarea::make('error_message')
                            ->label(false)
                            ->default($record->error_message)
                            ->rows(8)
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->modalHeading('Αποτυχία αποστολής'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
