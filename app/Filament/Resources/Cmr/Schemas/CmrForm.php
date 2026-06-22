<?php

namespace App\Filament\Resources\Cmr\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The CMR consignment-note form — used by both Create (standalone) and Edit
 * (incl. the pre-filled draft created from an Invoice / Δελτίο Αποστολής). Every
 * box is editable so the operator corrects Greek→English before printing. The
 * `number` counter is allocated server-side (CmrNote::creating), so it's not a
 * form field. Mirrors the 24 boxes of docs/reference/cmr-template.pdf.
 */
class CmrForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Στοιχεία CMR')
                ->columns(3)
                ->schema([
                    TextInput::make('reference_no')->label('Reference No.')->maxLength(40),
                    DateTimePicker::make('issued_at')->label('Ημερομηνία έκδοσης')->default(now()),
                    TextInput::make('copies_count')->label('Αντίτυπα')->numeric()->default(4)->minValue(1)->maxValue(9),
                ]),

            Section::make('Μέρη (1–4) — στα Αγγλικά')
                ->columns(2)
                ->schema([
                    Textarea::make('sender_text')->label('1 — Sender')->rows(4),
                    Textarea::make('consignee_text')->label('2 — Consignee')->rows(4),
                    Textarea::make('delivery_text')->label('3 — Place of delivery')->rows(3),
                    Section::make('4 — Taking over')
                        ->columns(2)
                        ->schema([
                            TextInput::make('taking_over_place')->label('Place'),
                            DateTimePicker::make('taking_over_at')->label('Date'),
                        ]),
                ]),

            Section::make('Εμπορεύματα (6–12)')
                ->schema([
                    Repeater::make('lines')
                        ->relationship()
                        ->label('Γραμμές')
                        ->columns(4)
                        ->defaultItems(1)
                        ->schema([
                            TextInput::make('marks_numbers')->label('6 — Marks & numbers'),
                            TextInput::make('packages_count')->label('7 — Packages')->numeric(),
                            TextInput::make('packing_method')->label('8 — Packing'),
                            TextInput::make('nature_en')->label('9 — Nature of goods')->columnSpan(4),
                            TextInput::make('statistical_no')->label('10 — Statistical no.'),
                            TextInput::make('weight_kg')->label('11 — Gross weight (kg)')->numeric(),
                            TextInput::make('volume_m3')->label('12 — Volume (m³)')->numeric(),
                            TextInput::make('adr_class')->label('ADR (επικίνδυνα)'),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['nature_en'] ?? null),
                ]),

            Section::make('Μεταφορέας (16–18, 23)')
                ->columns(2)
                ->schema([
                    TextInput::make('carrier_name')->label('16 — Carrier name'),
                    TextInput::make('carrier_address')->label('16 — Carrier address'),
                    TextInput::make('successive_carrier')->label('17 — Successive carriers'),
                    Textarea::make('carrier_reservations')->label('18 — Reservations & observations')->rows(2),
                    TextInput::make('tractor_plate')->label('Tractor plate'),
                    TextInput::make('trailer_plate')->label('Trailer plate'),
                ]),

            Section::make('Οδηγίες & συμφωνίες (5, 13, 19, 21)')
                ->columns(2)
                ->schema([
                    Textarea::make('annexed_documents')->label('5 — Annexed documents')->rows(2),
                    Textarea::make('sender_instructions')->label('13 — Sender instructions')->rows(2),
                    Textarea::make('special_agreements')->label('19 — Special agreements')->rows(2),
                    TextInput::make('established_place')->label('21 — Established in'),
                    DatePicker::make('established_on')->label('21 — on'),
                ]),

            Section::make('Ναύλα (14, 15, 20)')
                ->columns(3)
                ->collapsed()
                ->schema([
                    Toggle::make('freight_paid')->label('14 — Freight paid (αλλιώς: to be paid)'),
                    Select::make('charges_to_be_paid_by')->label('20 — To be paid by')
                        ->options(['sender' => 'Sender', 'consignee' => 'Consignee']),
                    TextInput::make('cash_on_delivery')->label('15 — Cash on delivery')->numeric(),
                    TextInput::make('carriage_charges')->label('Carriage charges')->numeric(),
                    TextInput::make('reductions')->label('Reductions')->numeric(),
                    TextInput::make('balance')->label('Balance')->numeric(),
                    TextInput::make('supplement')->label('Supplement')->numeric(),
                    TextInput::make('misc_charges')->label('Miscellaneous')->numeric(),
                    TextInput::make('total_charges')->label('Total to be paid')->numeric(),
                ]),

            Section::make('Σημειώσεις')
                ->schema([
                    Textarea::make('notes')->label('Εσωτερικές σημειώσεις')->rows(2),
                ]),
        ]);
    }
}
