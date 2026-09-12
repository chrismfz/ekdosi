<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Ενεργές συνεδρίες</x-slot>
        <x-slot name="description">
            Οι συσκευές/φυλλομετρητές που είναι αυτή τη στιγμή συνδεδεμένοι στον λογαριασμό σου.
            Αν δεις κάτι που δεν αναγνωρίζεις, τερμάτισέ το — και άλλαξε κωδικό.
        </x-slot>

        @php($rows = $this->sessions())

        @if (count($rows) === 0)
            <p style="font-size: .875rem; opacity: .65;">
                Δεν βρέθηκαν καταγεγραμμένες συνεδρίες.
            </p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(128,128,128,.28);">
                            <th style="padding: .5rem .75rem;">Συσκευή</th>
                            <th style="padding: .5rem .75rem;">IP</th>
                            <th style="padding: .5rem .75rem;">Τελευταία δραστηριότητα</th>
                            <th style="padding: .5rem .75rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom: 1px solid rgba(128,128,128,.16);">
                                <td style="padding: .5rem .75rem;" title="{{ $row['user_agent'] }}">
                                    {{ $row['device'] }}
                                </td>
                                <td style="padding: .5rem .75rem; font-variant-numeric: tabular-nums;">
                                    {{ $row['ip'] }}
                                </td>
                                <td style="padding: .5rem .75rem;">
                                    {{ $row['last_active'] }}
                                </td>
                                <td style="padding: .5rem .75rem; text-align: right;">
                                    @if ($row['is_current'])
                                        <x-filament::badge color="success">Τρέχουσα</x-filament::badge>
                                    @else
                                        <x-filament::button
                                            color="danger"
                                            size="xs"
                                            icon="heroicon-o-x-mark"
                                            wire:click="revoke(@js($row['id']))"
                                            wire:confirm="Τερματισμός αυτής της συνεδρίας;"
                                        >
                                            Τερματισμός
                                        </x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
