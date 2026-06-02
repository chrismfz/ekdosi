<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Services\Delivery\DeliveryLifecycleService;
use App\Support\MyData\Codes;
use App\Support\MyData\DeliveryCodes;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only view of a Δελτίο Αποστολής + its lines + the myDATA / delivery
 * lifecycle state. Codes render through the §8 label helpers.
 */
class DeliveryNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Στοιχεία')
                ->columns(3)
                ->schema([
                    TextEntry::make('invcode')->label('Κωδικός')->weight('bold')->copyable(),
                    TextEntry::make('deliveryType.name')->label('Τύπος')->placeholder('—'),
                    TextEntry::make('issued_at')->label('Έκδοση')->dateTime('d/m/Y H:i'),
                    TextEntry::make('move_purpose')
                        ->label('Σκοπός διακίνησης')
                        ->formatStateUsing(fn ($state) => DeliveryCodes::movePurposeLabel($state === null ? null : (int) $state))
                        ->placeholder('—'),
                    TextEntry::make('other_move_purpose_title')->label('Τίτλος σκοπού')->placeholder('—'),
                    TextEntry::make('mydata_type')->label('myDATA τύπος')->placeholder('—'),
                ]),

            Section::make('Παραλήπτης')
                ->columns(2)
                ->schema([
                    TextEntry::make('recipient_name')->label('Επωνυμία')->placeholder('— (ενδοδιακίνηση)'),
                    TextEntry::make('recipient_afm')->label('ΑΦΜ')->placeholder('000000000'),
                ]),

            Section::make('Διευθύνσεις')
                ->columns(2)
                ->schema([
                    TextEntry::make('loading_full')
                        ->label('Φόρτωση')
                        ->state(fn ($record) => trim(implode(' ', array_filter([
                            $record->loading_street, $record->loading_number,
                        ]))).', '.trim(implode(' ', array_filter([
                            $record->loading_postcode, $record->loading_city,
                        ])))),
                    TextEntry::make('delivery_full')
                        ->label('Παράδοση')
                        ->state(fn ($record) => trim(implode(' ', array_filter([
                            $record->delivery_street, $record->delivery_number,
                        ]))).', '.trim(implode(' ', array_filter([
                            $record->delivery_postcode, $record->delivery_city,
                        ])))),
                ]),

            Section::make('Μεταφορά')
                ->columns(3)
                ->schema([
                    TextEntry::make('transport_type')
                        ->label('Τρόπος μεταφοράς')
                        ->formatStateUsing(fn ($state) => DeliveryCodes::transportTypeLabel($state === null ? null : (int) $state))
                        ->placeholder('—'),
                    TextEntry::make('vehicle_number')->label('Πινακίδα')->placeholder('—'),
                    TextEntry::make('carrier_afm')->label('ΑΦΜ μεταφορέα')->placeholder('—'),
                    TextEntry::make('dispatch_at')->label('Έναρξη διακίνησης')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('third_party_collection')
                        ->label('Παραλαβή από τρίτο')
                        ->formatStateUsing(fn ($state) => $state ? 'Ναι' : 'Όχι'),
                ]),

            Section::make('Γραμμές')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('product_descr')->label('Περιγραφή')->placeholder('—'),
                            TextEntry::make('qty')->label('Ποσότητα'),
                            TextEntry::make('measurement_unit')
                                ->label('Μ.Μ.')
                                ->formatStateUsing(fn ($state) => Codes::QUANTITY_TYPES[(int) $state] ?? $state)
                                ->placeholder('—'),
                        ])
                        ->columns(3),
                ]),

            Section::make('Κατάσταση myDATA / διακίνησης')
                ->columns(3)
                ->schema([
                    TextEntry::make('local_status')->label('Τοπική κατάσταση')->badge(),
                    TextEntry::make('mydata_state')->label('myDATA')->badge()->placeholder('—'),
                    TextEntry::make('mydata_mark')->label('MARK')->placeholder('—')->copyable(),
                    TextEntry::make('delivery_state')
                        ->label('Διακίνηση')
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => DeliveryLifecycleService::stateLabel($state) ?? '—')
                        ->placeholder('—'),
                    TextEntry::make('mydata_url')
                        ->label('QR / σύνδεσμος')
                        ->url(fn ($record) => $record->mydata_url)
                        ->openUrlInNewTab()
                        ->placeholder('—')
                        ->columnSpan(2),
                ]),
        ]);
    }
}
