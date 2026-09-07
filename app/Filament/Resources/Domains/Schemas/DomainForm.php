<?php

namespace App\Filament\Resources\Domains\Schemas;

use App\Enums\DomainStatus;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Manual domain entry/edit (Πυλώνας A / A1b) — the way the portfolio works
 * BEFORE any registrar API. The authoritative name is sld + TLD (from the
 * tenant catalogue); fqdn/tld columns are derived on save (see the pages'
 * mutate hooks). Customer stays optional — an unassigned domain is a legal
 * state; the guarded «Ανάθεση σε πελάτη» action is what starts billing.
 */
class DomainForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(DomainRegistrarRegistry::class);

        return $schema->components([
            Section::make('Ταυτότητα')
                ->schema([
                    TextInput::make('sld')
                        ->label('Όνομα (χωρίς κατάληξη)')
                        ->required()
                        ->maxLength(63)
                        // Label rules (§5): unicode letters/digits/hyphen (IDN
                        // .ελ allowed), no dots/spaces, no leading/trailing
                        // hyphen — a pasted 'example.gr' must NOT become
                        // example.gr.gr via the fqdn derivation.
                        ->regex('/^(?!-)[\p{L}\p{N}-]+(?<!-)$/u')
                        ->validationMessages([
                            'regex' => 'Μόνο γράμματα/ψηφία/παύλες, χωρίς τελείες — η κατάληξη επιλέγεται δίπλα.',
                        ])
                        ->placeholder('example'),

                    Select::make('domain_tld_id')
                        ->label('TLD')
                        ->options(fn (): array => DomainTld::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('tld')
                            ->pluck('tld', 'id')
                            ->map(fn (string $tld): string => '.'.$tld)
                            ->all())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->helperText('Από τον κατάλογο «TLDs & τιμές» — προσθέστε εκεί ό,τι λείπει.'),

                    Select::make('status')
                        ->label('Κατάσταση')
                        ->options(DomainStatus::class)
                        ->default(DomainStatus::Active->value)
                        ->required()
                        ->native(false),

                    // Deliberately NO customer field: a customer set here would
                    // skip the guarded «Ανάθεση σε πελάτη» action (which creates
                    // the 1:1 ServiceContract) and leave the domain in a billing
                    // dead-end — assignment happens ONLY through the action.
                ])->columns(2),

            Section::make('Δρομολόγηση registrar')
                ->schema([
                    Select::make('registrar_connection_id')
                        ->label('Σύνδεση registrar')
                        ->options(fn (): array => DomainRegistrarConnection::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (DomainRegistrarConnection $c): array => [
                                $c->id => $registry->connectionLabel($c),
                            ])
                            ->all())
                        ->native(false)
                        ->placeholder('— από το TLD (default) / manual —')
                        ->helperText('Κενό = ισχύει η δρομολόγηση του TLD· τιμή εδώ νικά.'),

                    TextInput::make('registrar_domain_id')
                        ->label('Registrar domain id')
                        ->maxLength(60)
                        ->helperText('Π.χ. το αριθμητικό id του Openprovider — θα γεμίζει από το sync (A2).'),

                    TextInput::make('idn_script')
                        ->label('IDN script')
                        ->maxLength(30)
                        ->placeholder('π.χ. grek για .ελ'),
                ])->columns(3),

            Section::make('Ημερομηνίες')
                ->schema([
                    DatePicker::make('registered_at')->label('Καταχώρηση'),
                    DatePicker::make('transferred_at')->label('Μεταφορά σε εμάς'),
                    DatePicker::make('expires_at')->label('Λήξη')
                        ->helperText('Θα συγχρονίζεται από τον registrar (A2) — μέχρι τότε χειροκίνητα.'),
                ])->columns(3),

            Section::make('Ρυθμίσεις')
                ->schema([
                    Toggle::make('auto_renew')->label('Αυτόματη ανανέωση (auto-stage draft)'),
                    Toggle::make('transfer_lock')->label('Transfer lock'),
                    Toggle::make('whois_privacy')->label('WHOIS privacy / ID protection'),
                    Toggle::make('dnssec_enabled')->label('DNSSEC'),
                    Toggle::make('consent_publish')->label('Συναίνεση δημοσίευσης στοιχείων (WHOIS)')
                        ->helperText('GDPR: default απόκρυψη.'),
                    TextInput::make('price_override')
                        ->label('Τιμή ανανέωσης (override, €)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Κενό = η τιμή ανανέωσης του TLD.'),
                ])->columns(3),
        ]);
    }
}
