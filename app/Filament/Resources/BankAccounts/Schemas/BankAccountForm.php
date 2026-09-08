<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('bank_name')
                    ->label('Τράπεζα')
                    ->maxLength(120)
                    ->required()
                    ->placeholder('π.χ. Εθνική, Πειραιώς, Eurobank'),

                TextInput::make('iban')
                    ->label('IBAN')
                    ->maxLength(40)
                    ->placeholder('GR00 0000 0000 0000 0000 0000 000'),

                TextInput::make('account_name')
                    ->label('Δικαιούχος / Περιγραφή')
                    ->maxLength(160),

                TextInput::make('swift')
                    ->label('SWIFT / BIC')
                    ->maxLength(20),

                Toggle::make('is_active')
                    ->label('Ενεργός')
                    ->default(true)
                    ->helperText('Μόνο οι ενεργοί εμφανίζονται στις φόρμες πληρωμής & στα παραστατικά.'),

                Toggle::make('show_on_invoices')
                    ->label('Εμφάνιση στα τιμολόγια')
                    ->default(true)
                    ->helperText('Αν ενεργό, ο λογαριασμός (IBAN) τυπώνεται στο PDF των παραστατικών ως τρόπος πληρωμής. Κλείσ\' το για λογαριασμούς που δεν θες να βλέπει ο πελάτης.'),

                Textarea::make('notes')
                    ->label('Σημειώσεις')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
