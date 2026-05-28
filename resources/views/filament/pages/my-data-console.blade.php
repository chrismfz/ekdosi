<x-filament-panels::page>
    @php
        $money = fn ($v) => $v === null ? '—' : '€ ' . number_format((float) $v, 2, ',', '.');
    @endphp

    @if (! $ran)
        <x-filament::section>
            <x-slot name="heading">Ζωντανός έλεγχος myDATA</x-slot>
            <x-slot name="description">
                Λήψη των παραστατικών που έχετε υποβάλει στο AADE και σύγκριση με τα τοπικά
                δεδομένα για το επιλεγμένο διάστημα. Πατήστε «Έλεγχος με AADE» για να ξεκινήσετε.
            </x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Αυτή η σελίδα καλεί το AADE (RequestTransmittedDocs). Δεν τροποποιεί τίποτα —
                κάθε ασυμφωνία συνδέεται με το παραστατικό για να τη διορθώσετε από εκεί.
            </p>
        </x-filament::section>
    @endif

    @if ($error)
        <x-filament::section>
            <div class="text-danger-600 dark:text-danger-400 font-medium">
                {{ $error }}
            </div>
        </x-filament::section>
    @endif

    @if ($ran && $result)
        {{-- Summary cards --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Στο AADE</div>
                <div class="text-2xl font-bold">{{ $result['aadeTotal'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Τοπικά (υποβληθέντα)</div>
                <div class="text-2xl font-bold">{{ $result['localTotal'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Ασυμφωνίες</div>
                <div @class([
                    'text-2xl font-bold',
                    'text-success-600 dark:text-success-400' => $result['discrepancyCount'] === 0,
                    'text-warning-600 dark:text-warning-400' => $result['discrepancyCount'] > 0,
                ])>{{ $result['discrepancyCount'] }}</div>
            </x-filament::section>
        </div>

        @if ($result['discrepancyCount'] === 0)
            <x-filament::section>
                <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                    <span class="font-medium">Όλα τα τοπικά παραστατικά συμφωνούν με το AADE.</span>
                </div>
            </x-filament::section>
        @endif

        {{-- Discrepancy buckets --}}
        @foreach ([
            ['key' => 'stateMismatch', 'title' => 'Ασυμφωνία κατάστασης', 'color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'],
            ['key' => 'missingAtAade', 'title' => 'Λείπουν από το AADE', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'],
            ['key' => 'missingLocally', 'title' => 'Λείπουν τοπικά', 'color' => 'warning', 'icon' => 'heroicon-o-question-mark-circle'],
            ['key' => 'duplicateLocal', 'title' => 'Διπλά ΜΑΡΚ τοπικά', 'color' => 'danger', 'icon' => 'heroicon-o-document-duplicate'],
        ] as $bucket)
            @if (count($result[$bucket['key']]) > 0)
                <x-filament::section :collapsible="true">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon :icon="$bucket['icon']" @class([
                                'h-5 w-5',
                                'text-warning-500' => $bucket['color'] === 'warning',
                                'text-danger-500' => $bucket['color'] === 'danger',
                            ]) />
                            {{ $bucket['title'] }}
                            <x-filament::badge :color="$bucket['color']">{{ count($result[$bucket['key']]) }}</x-filament::badge>
                        </span>
                    </x-slot>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-gray-500 dark:text-gray-400">
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pr-4">Κωδικός</th>
                                    <th class="py-2 pr-4">ΜΑΡΚ</th>
                                    <th class="py-2 pr-4">Έκδοση</th>
                                    <th class="py-2 pr-4">Πελάτης</th>
                                    <th class="py-2 pr-4 text-right">Σύνολο</th>
                                    <th class="py-2 pr-4">Τοπικά</th>
                                    <th class="py-2 pr-4">AADE</th>
                                    <th class="py-2 pr-4">Πρόβλημα</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result[$bucket['key']] as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4 font-medium">{{ $row['invcode'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $row['mark'] }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $row['issuedAt'] ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $row['counterpartName'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['gross']) }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($row['localState'])
                                                <x-filament::badge :color="$row['localState'] === 'CANCELLED' ? 'danger' : 'success'">
                                                    {{ $row['localState'] }}
                                                </x-filament::badge>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            @if ($row['aadeState'])
                                                <x-filament::badge :color="$row['aadeState'] === 'CANCELLED' ? 'danger' : 'success'">
                                                    {{ $row['aadeState'] }}
                                                </x-filament::badge>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['problem'] }}</td>
                                        <td class="py-2">
                                            @if ($row['url'])
                                                <x-filament::link :href="$row['url']" size="sm">Άνοιγμα</x-filament::link>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endforeach

        {{-- Matched (collapsed by default) --}}
        @if (count($result['matched']) > 0)
            <x-filament::section :collapsible="true" :collapsed="true">
                <x-slot name="heading">
                    <span class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                        Συμφωνούν
                        <x-filament::badge color="success">{{ count($result['matched']) }}</x-filament::badge>
                    </span>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pr-4">Κωδικός</th>
                                <th class="py-2 pr-4">ΜΑΡΚ</th>
                                <th class="py-2 pr-4">Έκδοση</th>
                                <th class="py-2 pr-4">Πελάτης</th>
                                <th class="py-2 pr-4 text-right">Σύνολο</th>
                                <th class="py-2 pr-4">Κατάσταση</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['matched'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-medium">{{ $row['invcode'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $row['mark'] }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $row['issuedAt'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['counterpartName'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['gross']) }}</td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$row['aadeState'] === 'CANCELLED' ? 'danger' : 'success'">
                                            {{ $row['aadeState'] }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2">
                                        @if ($row['url'])
                                            <x-filament::link :href="$row['url']" size="sm">Άνοιγμα</x-filament::link>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
