<?php

namespace App\Filament\Resources\Cmr\Schemas;

use App\Support\Cmr\CmrGuidance;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The CMR consignment-note form — used by both Create (standalone) and Edit
 * (incl. the pre-filled draft from an Invoice / Δελτίο Αποστολής). Every box is
 * editable so the operator corrects Greek→English before printing. All inline
 * help (intro + per-field «τι γράφω πού») lives in App\Support\Cmr\CmrGuidance,
 * mirroring the Δελτίο Αποστολής guidance pattern. The `number` counter is
 * allocated server-side (CmrNote::creating), so it's not a form field.
 */
class CmrForm
{
    private static function help(string $field): ?string
    {
        return CmrGuidance::fieldHelp($field);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Non-blocking primer: what a CMR is + how to fill it.
            Section::make('Τι είναι το CMR & πώς το συμπληρώνω')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->schema([
                    Placeholder::make('cmr_intro')
                        ->hiddenLabel()
                        ->content(fn () => new HtmlString(
                            '<div class="text-sm" style="line-height:1.5">'.e(CmrGuidance::INTRO).'</div>'
                        )),
                ]),

            Section::make('Στοιχεία CMR')
                ->columns(3)
                ->schema([
                    TextInput::make('reference_no')->label('Reference No.')->maxLength(40)
                        ->helperText(self::help('reference_no')),
                    DateTimePicker::make('issued_at')->label('Ημερομηνία έκδοσης')->default(now())
                        ->helperText(self::help('issued_at')),
                    TextInput::make('copies_count')->label('Αντίτυπα')->numeric()->default(4)->minValue(1)->maxValue(9)
                        ->helperText(self::help('copies_count')),
                ]),

            Section::make('Μέρη (κουτιά 1–4) — στα Αγγλικά')
                ->description('Αποστολέας, παραλήπτης και τόποι. Γράψε επωνυμία, διεύθυνση και ΧΩΡΑ σε κάθε πεδίο.')
                ->columns(2)
                ->schema([
                    Textarea::make('sender_text')->label('1 — Sender (αποστολέας)')->rows(4)
                        ->helperText(self::help('sender_text')),
                    Textarea::make('consignee_text')->label('2 — Consignee (παραλήπτης)')->rows(4)
                        ->helperText(self::help('consignee_text')),
                    Textarea::make('delivery_text')->label('3 — Place of delivery (τόπος παράδοσης)')->rows(3)
                        ->helperText(self::help('delivery_text')),
                    Section::make('4 — Taking over (παραλαβή)')
                        ->columns(2)
                        ->schema([
                            TextInput::make('taking_over_place')->label('Place')
                                ->helperText(self::help('taking_over_place')),
                            DateTimePicker::make('taking_over_at')->label('Date')
                                ->helperText(self::help('taking_over_at')),
                        ]),
                ]),

            Section::make('Εμπορεύματα (κουτιά 6–12)')
                ->description(self::help('lines'))
                ->schema([
                    Repeater::make('lines')
                        ->relationship()
                        ->label('Γραμμές')
                        ->columns(4)
                        ->defaultItems(1)
                        ->schema([
                            TextInput::make('marks_numbers')->label('6 — Marks & numbers')
                                ->helperText(self::help('marks_numbers')),
                            TextInput::make('packages_count')->label('7 — Packages')->numeric()
                                ->helperText(self::help('packages_count')),
                            TextInput::make('packing_method')->label('8 — Packing')
                                ->helperText(self::help('packing_method')),
                            TextInput::make('nature_en')->label('9 — Nature of goods')->columnSpan(4)
                                ->helperText(self::help('nature_en')),
                            TextInput::make('statistical_no')->label('10 — Statistical no.')
                                ->helperText(self::help('statistical_no')),
                            TextInput::make('weight_kg')->label('11 — Gross weight (kg)')->numeric()
                                ->helperText(self::help('weight_kg')),
                            TextInput::make('volume_m3')->label('12 — Volume (m³)')->numeric()
                                ->helperText(self::help('volume_m3')),
                            TextInput::make('adr_class')->label('ADR (επικίνδυνα)')
                                ->helperText(self::help('adr_class')),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['nature_en'] ?? null),
                ]),

            Section::make('Μεταφορέας (κουτιά 16–18, 23)')
                ->columns(2)
                ->schema([
                    TextInput::make('carrier_name')->label('16 — Carrier name')
                        ->helperText(self::help('carrier_name')),
                    TextInput::make('carrier_address')->label('16 — Carrier address')
                        ->helperText(self::help('carrier_address')),
                    TextInput::make('successive_carrier')->label('17 — Successive carriers')
                        ->helperText(self::help('successive_carrier')),
                    Textarea::make('carrier_reservations')->label('18 — Reservations & observations')->rows(2)
                        ->helperText(self::help('carrier_reservations')),
                    TextInput::make('tractor_plate')->label('Tractor plate')
                        ->helperText(self::help('tractor_plate')),
                    TextInput::make('trailer_plate')->label('Trailer plate')
                        ->helperText(self::help('trailer_plate')),
                ]),

            Section::make('Οδηγίες & συμφωνίες (κουτιά 5, 13, 19, 21)')
                ->columns(2)
                ->collapsed()
                ->schema([
                    Textarea::make('annexed_documents')->label('5 — Annexed documents')->rows(2)
                        ->helperText(self::help('annexed_documents')),
                    Textarea::make('sender_instructions')->label('13 — Sender instructions')->rows(2)
                        ->helperText(self::help('sender_instructions')),
                    Textarea::make('special_agreements')->label('19 — Special agreements')->rows(2)
                        ->helperText(self::help('special_agreements')),
                    TextInput::make('established_place')->label('21 — Established in')
                        ->helperText(self::help('established_place')),
                    DatePicker::make('established_on')->label('21 — on')
                        ->helperText(self::help('established_on')),
                ]),

            Section::make('Ναύλα (κουτιά 14, 15, 20)')
                ->description('Προαιρετικά — συμπλήρωσε μόνο αν καταγράφεις κόστος μεταφορικών.')
                ->columns(3)
                ->collapsed()
                ->schema([
                    Toggle::make('freight_paid')->label('14 — Freight paid (αλλιώς: to be paid)')
                        ->helperText(self::help('freight_paid')),
                    Select::make('charges_to_be_paid_by')->label('20 — To be paid by')
                        ->options(['sender' => 'Sender', 'consignee' => 'Consignee'])
                        ->helperText(self::help('charges_to_be_paid_by')),
                    TextInput::make('cash_on_delivery')->label('15 — Cash on delivery')->numeric()
                        ->helperText(self::help('cash_on_delivery')),
                    TextInput::make('carriage_charges')->label('Carriage charges')->numeric()
                        ->helperText(self::help('carriage_charges')),
                    TextInput::make('reductions')->label('Reductions')->numeric()
                        ->helperText(self::help('reductions')),
                    TextInput::make('balance')->label('Balance')->numeric()
                        ->helperText(self::help('balance')),
                    TextInput::make('supplement')->label('Supplement')->numeric()
                        ->helperText(self::help('supplement')),
                    TextInput::make('misc_charges')->label('Miscellaneous')->numeric()
                        ->helperText(self::help('misc_charges')),
                    TextInput::make('total_charges')->label('Total to be paid')->numeric()
                        ->helperText(self::help('total_charges')),
                ]),

            Section::make('Σημειώσεις')
                ->schema([
                    Textarea::make('notes')->label('Εσωτερικές σημειώσεις')->rows(2)
                        ->helperText(self::help('notes')),
                ]),
        ]);
    }
}
