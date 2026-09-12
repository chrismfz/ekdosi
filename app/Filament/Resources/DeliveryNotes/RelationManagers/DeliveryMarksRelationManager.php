<?php

namespace App\Filament\Resources\DeliveryNotes\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only audit trail of every myDATA call for this δελτίο — the issue
 * INSERT, each e-transport lifecycle event (REGISTER_TRANSFER / CONFIRM_OUTCOME
 * / CANCEL) and any AADE REJECTED attempt. Two "View XML" actions surface the
 * raw request/response per row (legal audit + diagnosing rejections). The δελτίο
 * had no history view before; this is the delivery twin of the invoice
 * MyDataMarksRelationManager.
 */
class DeliveryMarksRelationManager extends RelationManager
{
    protected static string $relationship = 'marks';

    protected static ?string $title = 'Ιστορικό myDATA';

    protected static ?string $recordTitleAttribute = 'mark';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('mydata_action')
                    ->label('Ενέργεια')
                    ->badge()
                    // Human-readable Greek label (INSERT → «Καταχώρηση», STATE_SYNC →
                    // «Συγχρονισμός κατάστασης (ΑΑΔΕ)», …) while colour/sort/filter keep
                    // keying on the raw code.
                    ->formatStateUsing(fn ($state, $record) => $record->actionLabel())
                    ->color(fn (?string $state) => match ($state) {
                        'INSERT', 'PROVIDER_INSERT' => 'success',           // έκδοση — MARK εκδόθηκε
                        'REGISTER_TRANSFER' => 'info',   // έναρξη διακίνησης
                        'CONFIRM_OUTCOME' => 'success',  // παράδοση
                        'CONFIRM_RETURN' => 'warning',   // δήλωση επιστροφής (v2.0.2)
                        'CANCEL' => 'danger',            // ακύρωση
                        'STATE_SYNC' => 'warning',       // MYD-019: ακύρωση εκτός ekdosi, συγχρονίστηκε
                        'REJECTED', 'PROVIDER_REJECTED', 'PROVIDER_FAILED' => 'danger',          // αποτυχία με request/response forensic row
                        default => 'gray',
                    }),

                TextColumn::make('mark')
                    ->label('MARK')
                    ->placeholder('—')   // REJECTED rows have null mark
                    ->copyable(),

                // MYD-023: AADE's own MARK for the CANCELLATION act — distinct
                // evidence from the MARK of the document being cancelled, which is
                // what the column to the left holds.
                TextColumn::make('cancellation_mark')
                    ->label('ΜΑΡΚ ακύρωσης')
                    ->placeholder('—')   // only CANCEL rows carry one
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('provider_key')
                    ->label('Πάροχος')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('response')
                    ->label('Σημειώσεις / Απόκριση')
                    ->limit(90)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('mark_date')
                    ->label('Ημ/νία')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('mark_time')
                    ->label('Ώρα')
                    ->time('H:i:s')
                    ->placeholder('—'),

                TextColumn::make('invoice_url')
                    ->label('QR URL')
                    ->url(fn (?string $state) => $state)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view_request_xml')
                    ->label('Request XML')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Κλείσιμο')
                    ->schema(fn ($record) => [
                        Textarea::make('request_xml')
                            ->label(false)
                            ->default($record->request)
                            ->rows(20)
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->modalHeading(fn ($record) => 'Request XML — '.($record->mydata_action ?? '')),

                Action::make('view_response_xml')
                    ->label('Response XML')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Κλείσιμο')
                    ->schema(fn ($record) => [
                        Textarea::make('response_xml')
                            ->label(false)
                            ->default($record->response)
                            ->rows(20)
                            ->columnSpanFull()
                            ->readOnly(),
                    ])
                    ->modalHeading(fn ($record) => 'Response XML — '.($record->mydata_action ?? '')),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
