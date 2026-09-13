<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Support\MyData\DeliveryCodes;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only view of a Δελτίο Αποστολής, structured to MIRROR the invoice view:
 * identity / recipient / addresses / transport cards, a «Σχετιζόμενα» card that
 * binds the δελτίο to the sale (invoice) it dispatches, the «myDATA / Πάροχος»
 * submission card (shared cache + provider seal), the delivery «Lifecycle» card
 * (§8.22 movement state + the per-step marks), and print remarks. Lines, the
 * submission history (DeliveryMarks), internal notes, attachments and the
 * activity log are the bottom relation-manager tabs (DeliveryNoteResource).
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
                    // MYD-011: part of the legally-filed counterpart — an operator
                    // investigating a wrong-country AADE record must be able to see
                    // what was reported, and spot a wrong value BEFORE issuing.
                    // Rendered through the same accessor the payload uses, NOT the raw
                    // column: an unnormalised «Italy» displayed verbatim reads like a
                    // recorded country while the submitter refuses the note, and an
                    // inherited customer country is invisible in the column entirely.
                    TextEntry::make('recipient_country')
                        ->label('Χώρα')
                        ->state(function ($record): string {
                            if ($record->isInternalMovement()) {
                                return 'GR (ενδοδιακίνηση)';
                            }

                            if ($iso = $record->recipientCountryIso()) {
                                return $iso;
                            }

                            // Every branch warns — the issue is REFUSED in all of
                            // them, so this must not read as "it will sort itself
                            // out". They differ in WHICH field to fix, and are ordered
                            // exactly like DeliveryNoteSubmitter's message so the two
                            // surfaces never disagree: the note's own value first,
                            // because setting it resolves every state below.
                            if (filled($record->recipient_country)) {
                                return "«{$record->recipient_country}» — μη έγκυρος κωδικός ISO, η έκδοση θα απορριφθεί";
                            }

                            // A filed note whose country was never recorded: the
                            // customer's live country is deliberately NOT shown (it
                            // may differ from what was submitted) — the MARK is the
                            // record.
                            if ($record->hasBeenFiled()) {
                                return '— (δεν καταγράφηκε· βλ. MARK)';
                            }

                            if ($record->customer_id !== null && ! $record->recipientIsTheLinkedCustomer()) {
                                return '— ο παραλήπτης δεν είναι ο συνδεδεμένος πελάτης· απαιτείται δική του χώρα';
                            }

                            if (filled($record->customer?->country)) {
                                return "«{$record->customer?->country}» στον πελάτη — μη έγκυρος κωδικός ISO";
                            }

                            return '— λείπει· απαιτείται πριν την έκδοση';
                        }),
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
                    TextEntry::make('dispatch_at')->label('Έναρξη διακίνησης (προγρ.)')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('third_party_collection')
                        ->label('Παραλαβή από τρίτο')
                        ->formatStateUsing(fn ($state) => $state ? 'Ναι' : 'Όχι'),
                ]),

            // Σχετιζόμενα: binds the δελτίο to the sale (invoice) it dispatches —
            // the delivery side of the same end-to-end link the invoice shows
            // back («δελτία αποστολής»). Hidden when the note stands alone.
            Section::make('Σχετιζόμενα παραστατικά')
                ->icon('heroicon-o-link')
                ->visible(fn ($record) => $record->invoice_id !== null)
                ->schema([
                    TextEntry::make('related_invoice')
                        ->label('Αφορά την πώληση (τιμολόγιο)')
                        ->state(fn ($record) => $record->invoice?->invcode)
                        ->url(fn ($record) => $record->invoice
                            ? InvoiceResource::getUrl('view', [
                                'record' => $record->invoice,
                                'tenant' => $record->company,
                            ])
                            : null)
                        ->color('primary')
                        ->weight('bold')
                        ->helperText('Αυτό το δελτίο διακινεί τα είδη του παραπάνω παραστατικού πώλησης.'),
                ]),

            Section::make('myDATA / Πάροχος')
                ->description('Κατάσταση υποβολής (άμεσα ή μέσω παρόχου). Πλήρες ιστορικό + Request/Response XML στην καρτέλα «Ιστορικό υποβολών».')
                ->columns(4)
                ->schema([
                    IconEntry::make('mydata_sent')->label('Submitted')->boolean(),
                    TextEntry::make('mydata_state')
                        ->label('State')
                        ->badge()
                        ->color(fn (?string $state) => match ($state) {
                            'VALID' => 'success',
                            'CANCELLED' => 'danger',
                            null => 'gray',
                            default => 'warning',
                        })
                        ->placeholder('pending'),
                    TextEntry::make('mydata_mark')->label('MARK')->placeholder('—')->copyable(),
                    TextEntry::make('mydata_url')
                        ->label('QR / URL επαλήθευσης')
                        ->url(fn (?string $state) => $state)
                        ->openUrlInNewTab()
                        ->placeholder('—')
                        ->limit(60),
                    TextEntry::make('provider_key')
                        ->label('Πάροχος')
                        ->state(fn ($record) => $record->marks()->whereNotNull('provider_key')->latest('id')->value('provider_key'))
                        ->visible(fn ($record) => filled($record->marks()->whereNotNull('provider_key')->latest('id')->value('provider_key'))),
                    TextEntry::make('authentication_code')
                        ->label('Authentication code')
                        ->state(fn ($record) => $record->marks()->whereNotNull('authentication_code')->latest('id')->value('authentication_code'))
                        ->visible(fn ($record) => filled($record->marks()->whereNotNull('authentication_code')->latest('id')->value('authentication_code')))
                        ->copyable()
                        ->limit(40),
                ]),

            // Lifecycle: the e-transport movement (§8.22) — local intent + the AADE
            // delivery state, plus the ISSUER's per-step marks (RegisterTransfer,
            // ConfirmDeliveryReturn). The delivery OUTCOME / rejection marks are the
            // recipient's/carrier's, not written by our issuer flow — they surface in
            // the «Ιστορικό διακίνησης» events (delivery_note_events), not as a blank
            // cache field here.
            Section::make('Κατάσταση διακίνησης (lifecycle)')
                ->columns(4)
                ->schema([
                    TextEntry::make('local_status')->label('Τοπική κατάσταση')->badge(),
                    TextEntry::make('delivery_state')
                        ->label('Διακίνηση (ΑΑΔΕ)')
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => DeliveryLifecycleService::stateLabel($state) ?? '—')
                        ->placeholder('—'),
                    TextEntry::make('transfer_mark')->label('MARK έναρξης')->placeholder('—')->copyable(),
                    TextEntry::make('return_mark')->label('MARK επιστροφής')->placeholder('—')->copyable(), // v2.0.2 ConfirmDeliveryReturn
                ]),

            Section::make('Παρατηρήσεις (εκτύπωσης)')
                ->description('Εμφανίζονται στο PDF του παραλήπτη.')
                ->schema([
                    TextEntry::make('notes')->label(false)->placeholder('—')->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(fn ($record) => empty($record->notes)),
        ]);
    }
}
