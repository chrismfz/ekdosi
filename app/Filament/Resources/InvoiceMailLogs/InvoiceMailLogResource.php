<?php

namespace App\Filament\Resources\InvoiceMailLogs;

use App\Filament\Resources\InvoiceMailLogs\Pages\ListInvoiceMailLogs;
use App\Models\InvoiceMailLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-wide, READ-ONLY «Ιστορικό email» — every invoice-email attempt for the
 * current company (BelongsToCompany auto-scopes it). Rows are written only by the
 * SendInvoiceEmail job lifecycle; this resource never creates/edits/deletes.
 * «στάλθηκε» = SMTP accepted, not «delivered» (delivery/bounce tracking needs
 * provider webhooks — deferred).
 *
 * Per-invoice history stays on ViewInvoice; per-customer on the customer's
 * «Ιστορικό email» tab. This is the fleet-of-one overview + failure triage.
 */
class InvoiceMailLogResource extends Resource
{
    protected static ?string $model = InvoiceMailLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Ιστορικό email';

    protected static ?string $modelLabel = 'email';

    protected static ?string $pluralModelLabel = 'Ιστορικό email';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invoice.customer', 'triggeredByUser']);
    }

    // Read-only log — no create/edit/delete anywhere.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
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

                TextColumn::make('invoice.customer.name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->limit(28)
                    ->placeholder('—'),

                TextColumn::make('recipient')
                    ->label('Προς')
                    ->copyable()
                    ->searchable()
                    ->limit(28),

                TextColumn::make('trigger')
                    ->label('Τρόπος')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'auto' ? 'info' : 'warning')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'auto' => 'αυτόματο',
                        'batch' => 'μαζικό',
                        default => 'χειροκίνητο',
                    })
                    ->toggleable(),

                TextColumn::make('subject')
                    ->label('Θέμα')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sent_at')
                    ->label('Εστάλη')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('queued_at')
                    ->label('Σε ουρά')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
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
                SelectFilter::make('trigger')
                    ->label('Τρόπος')
                    ->options([
                        'auto' => 'αυτόματο',
                        'manual' => 'χειροκίνητο',
                        'batch' => 'μαζικό',
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
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceMailLogs::route('/'),
        ];
    }
}
