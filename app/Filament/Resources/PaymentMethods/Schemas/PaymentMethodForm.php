<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Support\MyData\Codes;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),

                TextInput::make('due_days')
                    ->label('Ημέρες πίστωσης (due days)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->helperText('0 = εξοφλείται στην έκδοση (ΔΕΝ μετράει στο υπόλοιπο πελάτη). >0 = επί πιστώσει, μετράει ως ανοιχτή οφειλή μέχρι να καταχωριστεί πληρωμή. ⚠ Η «Επί Πιστώσει» πρέπει να έχει >0 — αλλιώς κάθε τιμολόγιο εμφανίζεται «Εξοφλημένο» χωρίς πληρωμή.'),

                // G9: maps this method to an AADE §8.12 payment type so myDATA
                // filings carry the real type instead of always 3 (cash).
                Select::make('mydata_payment_type')
                    ->label('Τρόπος πληρωμής myDATA (§8.12)')
                    ->options(Codes::PAYMENT_METHODS)
                    ->placeholder('— προεπιλογή: 3 (Μετρητά / cash) —')
                    ->helperText('Ο κωδικός §8.12 που δηλώνεται στην ΑΑΔΕ. Κενό → δηλώνεται ως «Μετρητά» (3)· όρισέ τον για κατάθεση/κάρτα/επί πιστώσει ώστε να είναι σωστός ο τρόπος πληρωμής στο myDATA (χρησιμοποιείται και για τα εισερχόμενα WHMCS).')
                    // Legend built FROM Codes::PAYMENT_METHODS (the single source) so it
                    // can't drift from the dropdown options right above it.
                    ->hintIcon('heroicon-o-book-open')
                    ->hintIconTooltip(
                        collect(Codes::PAYMENT_METHODS)->map(fn (string $label, int $code): string => "{$code} {$label}")->implode(' · ')
                        .'. Πλήρης επεξήγηση: «Οδηγός κωδικών myDATA».'
                    ),
            ]);
    }
}
