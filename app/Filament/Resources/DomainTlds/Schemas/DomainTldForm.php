<?php

namespace App\Filament\Resources\DomainTlds\Schemas;

use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DomainTldForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(DomainRegistrarRegistry::class);

        return $schema->components([
            Section::make('Ταυτότητα & δρομολόγηση')
                ->schema([
                    TextInput::make('tld')
                        ->label('TLD')
                        ->required()
                        ->maxLength(30)
                        ->placeholder('gr, com, com.gr, ελ …')
                        // In-use TLD strings are frozen: renaming would desync
                        // every attached domain's stored tld/fqdn and silently
                        // re-route/re-price them (the EditDomain discipline).
                        ->disabled(fn (?DomainTld $record): bool => $record !== null && $record->domains()->exists())
                        ->helperText(fn (?DomainTld $record): string => $record !== null && $record->domains()->exists()
                            ? 'Κλειδωμένο — υπάρχουν domains σε αυτό το TLD. Για άλλο TLD, δημιουργήστε νέα εγγραφή.'
                            : 'Χωρίς την αρχική τελεία.')
                        // Ο κανόνας μοναδικότητας είναι per-tenant στη ΒΔ
                        // (unique company_id+tld) — το DB constraint είναι ο φρουρός.
                        ->dehydrateStateUsing(fn (string $state): string => mb_strtolower(ltrim(trim($state), '.'))),

                    Select::make('registrar_connection_id')
                        ->label('Registrar (δρομολόγηση)')
                        ->options(fn (): array => DomainRegistrarConnection::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (DomainRegistrarConnection $c): array => [
                                $c->id => ($c->label !== null && $c->label !== '' ? $c->label : $registry->label((string) $c->registrar)),
                            ])
                            ->all())
                        ->native(false)
                        ->placeholder('— καμία (manual) —')
                        ->helperText('Ποιά σύνδεση χειρίζεται αυτό το TLD (το «Auto Registration» της WHMCS). Κενό = χειροκίνητη διαχείριση.'),

                    Toggle::make('is_active')
                        ->label('Ενεργό')
                        ->default(true),
                ])->columns(3),

            Section::make('Όρια περιόδου & ονόματος')
                ->schema([
                    TextInput::make('min_years')->label('Ελάχιστα έτη')->numeric()->default(1)->minValue(1)->maxValue(10)
                        ->helperText('.gr → 2 (και μόνο πολλαπλάσια 2ετίας).'),
                    TextInput::make('max_years')->label('Μέγιστα έτη')->numeric()->default(10)->minValue(1)->maxValue(10),
                    TextInput::make('min_chars')->label('Ελάχ. χαρακτήρες')->numeric()->default(3)->minValue(1)->maxValue(63),
                    TextInput::make('max_chars')->label('Μέγ. χαρακτήρες')->numeric()->default(63)->minValue(1)->maxValue(63),
                    Toggle::make('allow_idn')->label('IDN (π.χ. .ελ)')->default(false),
                    Toggle::make('allow_transfer')->label('Επιτρέπονται μεταφορές')->default(true),
                ])->columns(3),

            Section::make('Grace / Redemption')
                ->schema([
                    TextInput::make('grace_period_days')->label('Grace (ημέρες)')->numeric()->default(0)->minValue(0),
                    TextInput::make('grace_fee')->label('Grace fee (€)')->numeric()->default(0)->minValue(0),
                    TextInput::make('redemption_period_days')->label('Redemption (ημέρες)')->numeric()->default(0)->minValue(0),
                    TextInput::make('redemption_fee')->label('Redemption fee (€)')->numeric()->default(0)->minValue(0),
                ])->columns(4),

            Section::make('Δυνατότητες')
                ->description('Flags ανά TLD — τα DNS management / Email forwarding είναι deferred (v1: μόνο σημαία).')
                ->schema([
                    Toggle::make('epp_code')->label('EPP code για μεταφορά')->default(true),
                    Toggle::make('id_protection')->label('ID protection')->default(false)
                        ->helperText('.gr: ΟΧΙ — το μητρώο δεν έχει privacy service.'),
                    Toggle::make('dns_management')->label('DNS management')->default(false),
                    Toggle::make('email_forwarding')->label('Email forwarding')->default(false),
                ])->columns(4),
        ]);
    }
}
