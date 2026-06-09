<?php

namespace App\Filament\Resources\VatCategories\Schemas;

use App\Support\MyData\Codes;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class VatCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(120)
                    ->columnSpan(2)
                    ->helperText('Short label — e.g. "24% κανονικός", "13% μειωμένος", "0% απαλλαγή".'),

                TextInput::make('rate')
                    ->label('Rate')
                    ->required()
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->live(onBlur: true)   // so the exemption picker reacts to the rate
                    ->suffix('%'),

                // G4: when the rate is 0%, AADE files it as vatCategory=7
                // (exempt) and REQUIRES a reason code (§8.3, 1–31). Set it here
                // so MyDataSubmitter can emit it. Only relevant for 0% rows.
                Select::make('vat_exemption_category')
                    ->label('Αιτία εξαίρεσης ΦΠΑ (για 0%)')
                    // Human-readable §8.3 reasons (verbatim legal citations) so
                    // the operator picks the RIGHT one — not a bare "Κατηγορία 16".
                    ->options(Codes::vatExemptionOptions())
                    ->searchable()
                    ->visible(fn (Get $get) => abs((float) $get('rate')) < 0.01)
                    ->required(fn (Get $get) => abs((float) $get('rate')) < 0.01)
                    ->helperText('§8.3 ΑΑΔΕ — υποχρεωτικό για 0% ώστε να υποβάλλονται τα παραστατικά. '
                        .'Για ΕΝΔΟΚΟΙΝΟΤΙΚΗ παράδοση / reverse charge επιλέξτε «16 — άρθρο 45» '
                        .'(πρώην 39α). Για εξαγωγή εκτός ΕΕ: «15 — άρθρο 44».'),

                // myDATA §8.2 ambiguity: a 4% rate maps to category 6 (pre-existing
                // island) OR 10 (αρ.31 ν.5057/2023); 3% → 9 (ν.5057). The submitter
                // derives 4%→6 by default; set this override to file 10 (or 9) on the
                // ν.5057 regime. Only shown for the ambiguous rates; null = derive.
                Select::make('mydata_vat_category')
                    ->label('Κατηγορία ΦΠΑ myDATA (override)')
                    ->options([
                        6 => '6 — 4% (νησιωτικός, προϋπάρχον)',
                        10 => '10 — 4% (αρ.31 ν.5057/2023)',
                        9 => '9 — 3% (αρ.31 ν.5057/2023)',
                    ])
                    ->visible(fn (Get $get) => in_array(round((float) $get('rate')), [3, 4], true))
                    ->helperText('Προαιρετικό. Αφήστε κενό για αυτόματη αντιστοίχιση (4%→6). '
                        .'Ορίστε το μόνο αν είστε στο καθεστώς ν.5057/2023 (4%→10, 3%→9).'),

                Toggle::make('is_default')
                    ->label('Default for new products')
                    ->helperText('Only one default per tenant — saving with this on will demote any other default automatically.'),

                Textarea::make('long_description')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
