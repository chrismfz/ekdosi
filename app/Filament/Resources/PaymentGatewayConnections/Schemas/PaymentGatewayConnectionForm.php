<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Schemas;

use App\Services\Payments\PaymentGatewayRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PaymentGatewayConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(PaymentGatewayRegistry::class);

        return $schema->components([
            Select::make('gateway')
                ->label('Τύπος')
                ->options(collect($registry->all())
                    ->mapWithKeys(fn ($g) => [$g->key() => $g->displayName()])
                    ->all())
                ->required()
                ->native(false)
                ->live()
                // The type binds the config schema — don't change it after
                // creation; add a new method instead.
                ->disabledOn('edit')
                ->helperText('Ο τύπος τρόπου πληρωμής. Δεν αλλάζει μετά τη δημιουργία.'),

            TextInput::make('label')
                ->label('Όνομα (όπως το βλέπει ο πελάτης)')
                ->maxLength(120)
                ->placeholder(fn (Get $get): string => filled($get('gateway'))
                    ? $registry->for((string) $get('gateway'))->displayName()
                    : '')
                ->helperText('Κενό → χρησιμοποιείται η προεπιλογή του τύπου.'),

            Toggle::make('is_active')
                ->label('Ενεργό (ορατό στον πελάτη)')
                ->default(false),

            TextInput::make('sort')
                ->label('Σειρά εμφάνισης')
                ->numeric()
                ->default(0)
                ->minValue(0),

            // Per-gateway settings, declared BY the gateway (configFields) and
            // nested under `config` (encrypted at rest). A new gateway brings its
            // own fields with no edit here.
            Section::make('Ρυθμίσεις τρόπου')
                ->statePath('config')
                ->visible(fn (Get $get): bool => filled($get('gateway')) && $get('gateway') !== 'none')
                ->schema(fn (Get $get): array => $registry->for((string) $get('gateway'))->configFields()),
        ]);
    }
}
