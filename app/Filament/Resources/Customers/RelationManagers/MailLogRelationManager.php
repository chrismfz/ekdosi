<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Per-customer email history — every invoice-email attempt for this customer,
 * across ALL their invoices (through Customer::invoiceMailLog). Read-only; rows
 * are written by the SendInvoiceEmail job lifecycle. Same columns as the
 * per-invoice log plus a «Παραστατικό» column so the operator sees which
 * invoice each mail belonged to. «sent» = SMTP accepted, not «delivered».
 */
class MailLogRelationManager extends RelationManager
{
    protected static string $relationship = 'invoiceMailLog';

    protected static ?string $title = 'Ιστορικό email';

    protected static ?string $recordTitleAttribute = 'subject';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Eager-load the two relations the columns read so the list doesn't
            // fire a query per row (invoice.invcode + triggeredByUser.name).
            ->modifyQueryUsing(fn ($query) => $query->with(['invoice', 'triggeredByUser']))
            ->columns([
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'sending' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'sent' => 'στάλθηκε',
                        'failed' => 'απέτυχε',
                        'sending' => 'αποστολή',
                        'queued' => 'σε ουρά',
                        default => (string) $state,
                    }),

                TextColumn::make('invoice.invcode')
                    ->label('Παραστατικό')
                    ->searchable(),

                TextColumn::make('trigger')
                    ->label('Τρόπος')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'auto' ? 'info' : 'warning')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'auto' => 'αυτόματο',
                        'batch' => 'μαζικό',
                        default => 'χειροκίνητο',
                    }),

                TextColumn::make('recipient')
                    ->label('Προς')
                    ->copyable()
                    ->limit(30),

                TextColumn::make('subject')
                    ->label('Θέμα')
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('sent_at')
                    ->label('Εστάλη')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                TextColumn::make('queued_at')
                    ->label('Σε ουρά')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('triggeredByUser.name')
                    ->label('Από')
                    ->placeholder('Σύστημα')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options([
                        'sent' => 'στάλθηκε',
                        'failed' => 'απέτυχε',
                        'sending' => 'αποστολή',
                        'queued' => 'σε ουρά',
                    ]),
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
