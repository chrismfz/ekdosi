<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Schemas;

use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * A0 keeps this to the account shell: registrar / label / mode / active. The
 * per-registrar credential fields (Openprovider username+password, grEPP EPP
 * host+user+pass) land WITH each adapter (A2/A4) using the einvoice
 * `provider_fields` + write-only-secret idiom — no free-form JSON editor here,
 * so a stored secret can never round-trip back into the browser.
 */
class DomainRegistrarConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(DomainRegistrarRegistry::class);

        return $schema->components([
            Select::make('registrar')
                ->label('Registrar')
                ->options($registry->selectOptions())
                ->required()
                ->native(false)
                // The key binds adapter + (future) credential schema — don't
                // change it after creation; add a new connection instead.
                ->disabledOn('edit')
                ->helperText('Ο πάροχος της σύνδεσης. Δεν αλλάζει μετά τη δημιουργία. «Manual» = χωρίς API — για domains που διαχειρίζεσαι χειροκίνητα.'),

            TextInput::make('label')
                ->label('Όνομα σύνδεσης')
                ->maxLength(120)
                ->placeholder('π.χ. «Openprovider MyIP», «FORTH EPP»')
                ->helperText('Κενό → εμφανίζεται το όνομα του registrar.'),

            Select::make('mode')
                ->label('Περιβάλλον')
                ->options([
                    'off' => 'Ανενεργό',
                    'sandbox' => 'Δοκιμαστικό (sandbox / UAT)',
                    'production' => 'Παραγωγή',
                ])
                ->default('off')
                ->required()
                ->native(false)
                ->helperText('Μόνο η ρητή «Παραγωγή» χτυπά live endpoints — οτιδήποτε άλλο συμπεριφέρεται ως sandbox.'),

            Toggle::make('is_active')
                ->label('Ενεργή σύνδεση')
                ->default(false)
                ->helperText('Ανενεργή = δεν προσφέρεται για νέα domains/ενέργειες.'),
        ]);
    }
}
