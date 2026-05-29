<x-filament-panels::page>
    @php $money = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.'); @endphp

    @if (! $ran)
        <x-filament::section>
            <x-slot name="heading">Επισκόπηση Ε3</x-slot>
            <x-slot name="description">
                Αθροιστικά στοιχεία Ε3 (ανά τύπο/κατηγορία χαρακτηρισμού) όπως τα τηρεί το myDATA για το ΑΦΜ μας.
                Read-only — η σελίδα δεν τροποποιεί τίποτα.
            </x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">Πατήστε «Λήψη Ε3 από myDATA» για ένα διάστημα.</p>
        </x-filament::section>
    @endif

    @if ($error)
        <x-filament::section>
            <div class="text-danger-600 dark:text-danger-400 font-medium">{{ $error }}</div>
        </x-filament::section>
    @endif

    @if ($ran && $result)
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Εγγραφές</div>
                <div class="text-2xl font-bold">{{ $result['docCount'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Σύνολο αξίας</div>
                <div class="text-2xl font-bold">{{ $money($result['total']) }}</div>
            </x-filament::section>
        </div>

        @if (count($result['rows']) === 0)
            <x-filament::section>
                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-inbox" class="h-6 w-6" />
                    <span>Δεν επιστράφηκαν στοιχεία Ε3 για το διάστημα.</span>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Ανά τύπο χαρακτηρισμού</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pr-4">Τύπος (E3)</th>
                                <th class="py-2 pr-4">Κατηγορία</th>
                                <th class="py-2 pr-4 text-right">Πλήθος</th>
                                <th class="py-2 text-right">Αξία</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['rows'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $row['classType'] }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $row['classCategory'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['count'] }}</td>
                                    <td class="py-2 text-right whitespace-nowrap">{{ $money($row['value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
