<?php

namespace App\Filament\Resources\DeliveryNotes\RelationManagers;

use App\Models\DeliveryNoteEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only timeline of the AADE-reported lifecycle history (§4.1) — what the
 * carrier & recipient did to the shipment (RegisterTransfer / ConfirmOutcome /
 * Rejection), each with timestamp / actor ΑΦΜ / event MARK. Rows are written
 * ONLY by DeliveryLifecycleService::syncLifecycleHistory when the operator runs
 * «Έλεγχος κατάστασης (ΑΑΔΕ)»; nothing here creates/edits/deletes. Closes the
 * gap left after PR #179, where refreshStatus read the status but discarded the
 * event history.
 */
class DeliveryEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Ιστορικό διακίνησης';

    protected static ?string $recordTitleAttribute = 'event_type';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $issuerAfm = $this->getOwnerRecord()->company?->afm;

        return $table
            ->defaultSort('event_timestamp', 'asc')
            ->emptyStateHeading('Κανένα γεγονός διακίνησης')
            ->emptyStateDescription('Πατήστε «Έλεγχος κατάστασης (ΑΑΔΕ)» για να αντληθεί το ιστορικό από το myDATA.')
            ->columns([
                TextColumn::make('event_timestamp')
                    ->label('Πότε')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('event_type')
                    ->label('Γεγονός')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'RegisterTransfer' => 'info',
                        'ConfirmOutcome' => 'success',
                        'Rejection' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (DeliveryNoteEvent $record) => $record->typeLabel()),

                TextColumn::make('actor_vat')
                    ->label('Από')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state !== null && $state === $issuerAfm
                        ? 'Εσείς (εκδότης)'
                        : ($state ?? '—')),

                TextColumn::make('summary')
                    ->label('Λεπτομέρειες')
                    ->state(fn (DeliveryNoteEvent $record) => $record->summary())
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('event_mark')
                    ->label('MARK γεγονότος')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),
            ]);
    }
}
