<?php

namespace App\Filament\Resources\Domains\Schemas;

use App\Models\Domain;
use App\Services\Domains\DomainRegistrarRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * The per-domain View header (Πυλώνας A) — everything at a glance without
 * opening the edit form: identity, THE TWO CLOCKS side by side (registrar
 * expiry vs the ServiceContract billing clock — the §3.4 discipline made
 * visible), routing, and the flags. The A2/A3 registrar command actions dock
 * on this page; sync fields (last_synced_at/sync_error) already have a home.
 */
class DomainInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ταυτότητα')
                ->columns(4)
                ->schema([
                    TextEntry::make('fqdn')
                        ->label('Domain')
                        ->weight('bold')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Κατάσταση')
                        ->badge(),
                    TextEntry::make('customer.name')
                        ->label('Πελάτης')
                        ->placeholder('— αδέσποτο —'),
                    TextEntry::make('registrar')
                        ->label('Registrar')
                        ->badge()
                        ->color('gray')
                        // THE shared routing + label pair — same answer as the
                        // list column and (crucially) the sync itself.
                        ->state(fn (Domain $record): string => app(DomainRegistrarRegistry::class)
                            ->connectionLabel($record->effectiveRegistrarConnection())),
                ]),

            Section::make('Τα δύο ρολόγια')
                ->description('Λήξη = η αλήθεια του registrar (sync στο A2)· Επόμενη χρέωση = το ρολόι τιμολόγησης της υπηρεσίας. Συμφωνούν, δεν ταυτίζονται.')
                ->columns(4)
                ->schema([
                    TextEntry::make('expires_at')
                        ->label('Λήξη (registrar)')
                        ->date('d/m/Y')
                        ->placeholder('—')
                        // Shared semantics (Domain::isExpired = strictly BEFORE
                        // today — on the expiry date the name is still held).
                        ->color(fn (Domain $record): ?string => $record->expires_at === null ? null
                            : ($record->isExpired() ? 'danger'
                                : ($record->isExpiringSoon() ? 'warning' : 'success')))
                        ->helperText(function (Domain $record): ?string {
                            if ($record->expires_at === null) {
                                return null;
                            }
                            if ($record->expires_at->isToday()) {
                                return 'λήγει σήμερα';
                            }

                            return $record->isExpired()
                                ? 'Έληξε πριν '.$record->expires_at->diffForHumans(short: true, syntax: Carbon::DIFF_ABSOLUTE)
                                : 'σε '.$record->expires_at->diffForHumans(short: true, syntax: Carbon::DIFF_ABSOLUTE);
                        }),
                    TextEntry::make('serviceContract.next_due_date')
                        ->label('Επόμενη χρέωση (υπηρεσία)')
                        ->date('d/m/Y')
                        ->placeholder('— χωρίς συμβόλαιο —'),
                    TextEntry::make('serviceContract.amount')
                        ->label('Ποσό ανανέωσης')
                        ->money('EUR')
                        ->placeholder('—')
                        ->helperText(fn (Domain $record): ?string => $record->serviceContract?->billing_cycle?->label()),
                    TextEntry::make('registered_at')
                        ->label('Καταχώρηση / Μεταφορά')
                        ->placeholder('—')
                        // Either part may be null (a transfer-in often has no
                        // known original registration date) — no dangling «·».
                        ->state(function (Domain $record): ?string {
                            $parts = array_filter([
                                $record->registered_at?->format('d/m/Y'),
                                $record->transferred_at !== null ? 'σε εμάς '.$record->transferred_at->format('d/m/Y') : null,
                            ]);

                            return $parts === [] ? null : implode(' · ', $parts);
                        }),
                ]),

            Section::make('Registrar & ρυθμίσεις')
                ->columns(4)
                ->schema([
                    TextEntry::make('registrar_domain_id')
                        ->label('Registrar domain id')
                        ->placeholder('— (θα γεμίσει από sync A2) —')
                        ->copyable(),
                    TextEntry::make('flags')
                        ->label('Flags')
                        ->state(function (Domain $record): string {
                            $on = array_keys(array_filter([
                                'Auto-renew' => $record->auto_renew,
                                'Transfer lock' => $record->transfer_lock,
                                'WHOIS privacy' => $record->whois_privacy,
                                'DNSSEC' => $record->dnssec_enabled,
                                'Δημοσίευση WHOIS' => $record->consent_publish,
                            ]));

                            return $on === [] ? '—' : implode(' · ', $on);
                        }),
                    TextEntry::make('price_override')
                        ->label('Τιμή override')
                        ->money('EUR')
                        ->placeholder('— τιμοκατάλογος TLD —'),
                    TextEntry::make('last_synced_at')
                        ->label('Τελευταίο sync')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('— ποτέ (A2) —')
                        ->helperText(fn (Domain $record): ?string => $record->sync_error !== null
                            ? '⚠ '.$record->sync_error
                            : null),
                ]),
        ]);
    }
}
