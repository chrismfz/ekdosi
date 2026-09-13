<?php

namespace App\Filament\Resources\InboundDeliveryNotes\Schemas;

use App\Models\InboundDeliveryNote;
use App\Support\MyData\DeliveryCodes;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Slice 4b — read-only view of a staged inbound movement: who sent it, its AADE
 * status + our disposition, and the delivery lifecycle timeline (as staged by the
 * fetch / refreshed by «Έλεγχος κατάστασης»).
 */
class InboundDeliveryNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Στοιχεία')
                ->columns(3)
                ->schema([
                    TextEntry::make('issuer_name')->label('Εκδότης')->placeholder('—'),
                    TextEntry::make('issuer_afm')->label('ΑΦΜ εκδότη')->placeholder('—'),
                    TextEntry::make('invoice_type')->label('Τύπος')->badge()->placeholder('—'),
                    TextEntry::make('aa')->label('ΑΑ')->placeholder('—'),
                    TextEntry::make('issue_date')->label('Ημ/νία έκδοσης')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('mydata_mark')->label('MARK')->copyable()->placeholder('—'),
                ]),

            Section::make('Κατάσταση')
                ->columns(3)
                ->schema([
                    TextEntry::make('aade_delivery_status')
                        ->label('Κατάσταση ΑΑΔΕ')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => DeliveryCodes::deliveryStatusLabel($state === null ? null : (int) $state) ?? '—'),
                    TextEntry::make('local_state')
                        ->label('Διάθεση')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => InboundDeliveryNote::stateLabel($state) ?? '—'),
                    TextEntry::make('last_fetched_at')->label('Τελευταία λήψη')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('reject_mark')->label('MARK απόρριψης')->placeholder('—'),
                    TextEntry::make('outcome_mark')->label('MARK παραλαβής')->placeholder('—'),
                    TextEntry::make('qr_code_url')->label('QR δελτίου')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Ιστορικό διακίνησης')
                ->visible(fn (InboundDeliveryNote $record): bool => ! empty($record->lifecycle))
                ->schema([
                    RepeatableEntry::make('lifecycle')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('type')->label('Γεγονός'),
                            TextEntry::make('timestamp')->label('Χρόνος')->placeholder('—'),
                            TextEntry::make('actor_vat')->label('ΑΦΜ ενεργούντος')->placeholder('—'),
                            TextEntry::make('mark')->label('MARK γεγονότος')->placeholder('—'),
                        ]),
                ]),
        ]);
    }
}
