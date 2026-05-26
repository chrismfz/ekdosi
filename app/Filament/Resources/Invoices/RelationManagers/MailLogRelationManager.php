<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Mail send history for an invoice. Read-only — rows are written by
 * the SendInvoiceEmail job's lifecycle. Operators see the most-recent
 * attempts first.
 *
 * Status badges follow the lifecycle:
 *   queued   (gray)    job dispatched, not yet processed
 *   sending  (info)    in the worker, mid-send
 *   sent     (success) SMTP accepted the mail
 *   failed   (danger)  exception caught; see error_message column
 *
 * "sent" does NOT mean "customer received it" — it means the SMTP
 * server accepted the mail for delivery. Bounce / delivery / open
 * tracking requires provider webhooks (deferred PR).
 */
class MailLogRelationManager extends RelationManager
{
    protected static string $relationship = 'mailLog';

    protected static ?string $title = 'Send history';

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
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'sent'    => 'success',
                        'failed'  => 'danger',
                        'sending' => 'info',
                        'queued'  => 'gray',
                        default   => 'gray',
                    }),

                TextColumn::make('trigger')
                    ->label('Trigger')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'auto' ? 'info' : 'warning')
                    ->formatStateUsing(fn (?string $state) => $state === 'auto' ? 'auto (myDATA accept)' : 'manual'),

                TextColumn::make('recipient')
                    ->label('To')
                    ->copyable()
                    ->limit(30),

                TextColumn::make('subject')
                    ->label('Subject')
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('queued_at')
                    ->label('Queued')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—'),

                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—'),

                TextColumn::make('failed_at')
                    ->label('Failed')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('triggeredByUser.name')
                    ->label('By')
                    ->placeholder('system')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view_error')
                    ->label('Error')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn ($record) => ! empty($record->error_message))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn ($record) => [
                        Textarea::make('error_message')
                            ->label(false)
                            ->default($record->error_message)
                            ->rows(8)
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->modalHeading('Send failure'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
