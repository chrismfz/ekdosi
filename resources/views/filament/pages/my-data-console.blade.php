<x-filament-panels::page>
    @php
        $money = fn ($v) => $v === null ? '—' : '€ ' . number_format((float) $v, 2, ',', '.');
    @endphp

    @if (! $ran)
        <x-filament::section>
            <x-slot name="heading">Ζωντανός έλεγχος myDATA</x-slot>
            <x-slot name="description">
                Δύο κατευθύνσεις, ίδια πηγή (RequestTransmittedDocs) — η σελίδα δεν τροποποιεί τίποτα.
            </x-slot>
            <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-clipboard-document-check" class="mt-0.5 h-5 w-5 text-primary-500" />
                    <span>
                        <strong>Έλεγχος δικών μας στο myDATA</strong> — τα παραστατικά που εκδώσαμε εμείς:
                        υπάρχουν όλα στο myDATA και συμφωνούν οι καταστάσεις/ακυρώσεις;
                    </span>
                </li>
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="mt-0.5 h-5 w-5 text-warning-500" />
                    <span>
                        <strong>Αδέσποτα από myDATA</strong> — η ανάποδη ματιά: παραστατικά που έχει το
                        myDATA για το ΑΦΜ μας αλλά <em>δεν</em> υπάρχουν στο ekdosi (π.χ. εκδόθηκαν από
                        e-τιμολόγιο ΑΑΔΕ ή άλλο πρόγραμμα).
                    </span>
                </li>
            </ul>
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
        @php $mode = $resultMode ?? 'compare'; @endphp

        @if ($mode === 'inbound')
            {{-- ============================================================
                 Direction 2 — myDATA → US ("αδέσποτα").
                 Same fetch, AADE-centric framing: of everything myDATA holds,
                 which is linked to a local invoice and which is orphaned.
                 ============================================================ --}}
            @php
                $linkedCount = count($result['matched']) + count($result['stateMismatch']);
                $orphans = $result['missingLocally'];
                $linked = array_merge($result['matched'], $result['stateMismatch']);
            @endphp

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                    <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Στο myDATA</div>
                    <div class="text-2xl font-bold">{{ $result['aadeTotal'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Συνδεδεμένα</div>
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $linkedCount }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Αδέσποτα (μόνο στο myDATA)</div>
                    <div @class([
                        'text-2xl font-bold',
                        'text-success-600 dark:text-success-400' => count($orphans) === 0,
                        'text-warning-600 dark:text-warning-400' => count($orphans) > 0,
                    ])>{{ count($orphans) }}</div>
                </x-filament::section>
            </div>

            @if ($result['aadeTotal'] === 0)
                <x-filament::section>
                    <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-o-inbox" class="h-6 w-6" />
                        <span>Το myDATA δεν επέστρεψε παραστατικά για το διάστημα.</span>
                    </div>
                </x-filament::section>
            @elseif (count($orphans) === 0)
                <x-filament::section>
                    <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                        <span class="font-medium">Δεν βρέθηκαν αδέσποτα — όλα όσα έχει το myDATA είναι συνδεδεμένα με τοπικό παραστατικό.</span>
                    </div>
                </x-filament::section>
            @endif

            {{-- Orphans — the actionable list --}}
            @if (count($orphans) > 0)
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="h-5 w-5 text-warning-500" />
                            Αδέσποτα παραστατικά (στο myDATA, όχι στο ekdosi)
                            <x-filament::badge color="warning">{{ count($orphans) }}</x-filament::badge>
                        </span>
                    </x-slot>
                    <x-slot name="description">
                        Υπάρχουν στο myDATA για το ΑΦΜ μας αλλά δεν έχουν τοπική εγγραφή — εκδόθηκαν
                        πιθανότατα από e-τιμολόγιο ή άλλο πρόγραμμα. Καταχωρίστε τα στο ekdosi ή αγνοήστε.
                    </x-slot>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-gray-500 dark:text-gray-400">
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pr-4">ΜΑΡΚ</th>
                                    <th class="py-2 pr-4">Έκδοση</th>
                                    <th class="py-2 pr-4">Πελάτης</th>
                                    <th class="py-2 pr-4 text-right">Σύνολο</th>
                                    <th class="py-2 pr-4">myDATA</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orphans as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $row['mark'] }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $row['issuedAt'] ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $row['counterpartName'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['gross']) }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($row['aadeState'])
                                                <x-filament::badge :color="$row['aadeState'] === 'CANCELLED' ? 'danger' : 'success'">
                                                    {{ $row['aadeState'] }}
                                                </x-filament::badge>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            {{-- Linked (collapsed by default) --}}
            @if (count($linked) > 0)
                <x-filament::section :collapsible="true" :collapsed="true">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-link" class="h-5 w-5 text-success-500" />
                            Συνδεδεμένα με τοπικό παραστατικό
                            <x-filament::badge color="success">{{ count($linked) }}</x-filament::badge>
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
                                    <th class="py-2 pr-4">Σύνδεση</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($linked as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4 font-medium">{{ $row['invcode'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $row['mark'] }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $row['issuedAt'] ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $row['counterpartName'] ?? '—' }}</td>
                                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['gross']) }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($row['problem'])
                                                <x-filament::badge color="warning">Διαφορά κατάστασης</x-filament::badge>
                                            @else
                                                <x-filament::badge color="success">Συνδεδεμένο</x-filament::badge>
                                            @endif
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
        @else
            {{-- ============================================================
                 Direction 1 — OUR records → myDATA (the original view).
                 ============================================================ --}}
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
                ['key' => 'missingLocally', 'title' => 'Λείπουν τοπικά (αδέσποτα)', 'color' => 'warning', 'icon' => 'heroicon-o-question-mark-circle'],
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
    @endif
</x-filament-panels::page>
