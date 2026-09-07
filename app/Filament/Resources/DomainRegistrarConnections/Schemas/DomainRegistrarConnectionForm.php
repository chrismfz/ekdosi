<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Schemas;

use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Account shell (registrar / label / mode / active) + the per-registrar
 * credential fields declared in config → ekdosi.domains.registrar_fields (the
 * einvoice `provider_fields` idiom): labeled inputs into the encrypted
 * `config` blob, secrets WRITE-ONLY (EditDomainRegistrarConnection never
 * loads them back — blank on edit means «unchanged»).
 *
 * STATIC schema (the PaymentGatewayConnectionForm lesson): every registrar's
 * Group is built ONCE at mount and only the selected registrar's is shown — a
 * reactively-BUILT schema skips fill() and silently drops state. Hidden Groups
 * are excluded from validation and dehydration, so keys never bleed between
 * registrars (field names may therefore repeat across registrars safely here,
 * since only ONE group dehydrates — but keep them distinct when possible).
 */
class DomainRegistrarConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(DomainRegistrarRegistry::class);

        $credentialGroups = [];
        $fieldMap = config('ekdosi.domains.registrar_fields', []);
        foreach ((is_array($fieldMap) ? $fieldMap : []) as $registrarKey => $fields) {
            $inputs = [];
            foreach ((is_array($fields) ? $fields : []) as $name => $meta) {
                $input = TextInput::make($name)
                    ->label((string) ($meta['label'] ?? $name))
                    ->maxLength(191);
                if ($meta['secret'] ?? false) {
                    $input = $input
                        ->password()
                        ->revealable()
                        ->helperText('Encrypted at rest. Κενό στην επεξεργασία = παραμένει το αποθηκευμένο.')
                        // Only a typed value dehydrates — a blank edit never
                        // overwrites the stored secret (see the Edit page pair).
                        ->dehydrated(fn (?string $state) => filled($state));
                } else {
                    $input = $input->required();
                }
                $inputs[] = $input;
            }
            $credentialGroups[] = Group::make($inputs)
                ->statePath('config')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('registrar') === $registrarKey);
        }

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
                // Production is the normal case (the A2 adapter is read-only, so
                // real creds are safe from day one); the fail-safe stays under
                // the hood — an invalid/other value still behaves as sandbox.
                ->default('production')
                ->required()
                ->native(false)
                ->helperText('«Δοκιμαστικό» = τα sandbox endpoints του registrar (ξεχωριστός λογαριασμός στο Openprovider).'),

            Toggle::make('is_active')
                ->label('Ενεργή σύνδεση')
                ->default(false)
                ->helperText('Ανενεργή = δεν προσφέρεται για νέα domains/ενέργειες.'),

            ...$credentialGroups,
        ]);
    }
}
