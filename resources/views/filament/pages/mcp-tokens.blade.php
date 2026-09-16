<x-filament-panels::page>
    {{-- Freshly-minted token — shown right after minting, cleared on the next action or reload. --}}
    @if ($this->plainToken)
        <x-filament::section>
            <x-slot name="heading">Το νέο σου token</x-slot>
            <x-slot name="description">
                Αντίγραψέ το <strong>τώρα</strong> — εμφανίζεται μόνο μία φορά και δεν ανακτάται.
            </x-slot>

            <div
                x-data="{ copied: false, token: @js($this->plainToken) }"
                style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;"
            >
                <code style="flex:1 1 20rem; min-width:0; overflow-x:auto; white-space:nowrap; padding:.5rem .75rem; border-radius:.5rem; background:rgba(128,128,128,.12); font-size:.8rem;">{{ $this->plainToken }}</code>
                <x-filament::button
                    size="sm"
                    icon="heroicon-o-clipboard-document"
                    x-on:click="if (navigator.clipboard) { navigator.clipboard.writeText(token).then(() => { copied = true; setTimeout(() => copied = false, 1500) }).catch(() => {}) }"
                >
                    <span x-text="copied ? 'Αντιγράφηκε!' : 'Αντιγραφή'">Αντιγραφή</span>
                </x-filament::button>
            </div>

            <p style="margin-top:.75rem; font-size:.8rem; opacity:.7;">
                Δώσ' το στον MCP client σου ως <code>Authorization: Bearer &lt;token&gt;</code>.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Τι είναι αυτό</x-slot>
        <x-slot name="description">
            Ένα προσωπικό κλειδί που επιτρέπει σε έναν MCP client (Claude Desktop / CLI / curl) να
            μιλήσει με το ekdosi ως εσύ, <strong>δεμένο στην τρέχουσα εταιρεία</strong>. Έχει ακριβώς
            τα δικαιώματα που έχεις κι εσύ στο panel.
        </x-slot>
        <p style="font-size:.8rem; opacity:.7;">
            Για τον connector του <strong>claude.ai</strong> δεν χρειάζεσαι token εδώ — εκείνος
            συνδέεται με OAuth. Το token αφορά Desktop / CLI / curl.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Τα κλειδιά μου</x-slot>

        @php($rows = $this->tokens())

        @if (count($rows) === 0)
            <p style="font-size:.875rem; opacity:.65;">
                Δεν έχεις ενεργά κλειδιά για αυτή την εταιρεία.
            </p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.875rem;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid rgba(128,128,128,.28);">
                            <th style="padding:.5rem .75rem;">Όνομα</th>
                            <th style="padding:.5rem .75rem;">Δημιουργήθηκε</th>
                            <th style="padding:.5rem .75rem;">Τελευταία χρήση</th>
                            <th style="padding:.5rem .75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom:1px solid rgba(128,128,128,.16);">
                                <td style="padding:.5rem .75rem;">{{ $row['name'] }}</td>
                                <td style="padding:.5rem .75rem; font-variant-numeric:tabular-nums;">{{ $row['created'] }}</td>
                                <td style="padding:.5rem .75rem;">{{ $row['last_used'] }}</td>
                                <td style="padding:.5rem .75rem; text-align:right;">
                                    <x-filament::button
                                        color="danger"
                                        size="xs"
                                        icon="heroicon-o-trash"
                                        wire:click="revoke(@js($row['id']))"
                                        wire:confirm="Ανάκληση αυτού του κλειδιού; Όποιος client το χρησιμοποιεί θα σταματήσει αμέσως."
                                    >
                                        Ανάκληση
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
