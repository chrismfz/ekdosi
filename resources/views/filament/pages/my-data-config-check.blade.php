<x-filament-panels::page>
    @php
        $badge = [
            'ok'    => ['color' => 'success', 'icon' => 'heroicon-o-check-circle',        'label' => 'Εντάξει'],
            'warn'  => ['color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle','label' => 'Προσοχή'],
            'error' => ['color' => 'danger',  'icon' => 'heroicon-o-x-circle',            'label' => 'Σφάλμα'],
        ];
    @endphp

    {{-- Headline: one glance at overall readiness. --}}
    <x-filament::section>
        <x-slot name="heading">
            <span class="flex items-center gap-2">
                Έλεγχος ρυθμίσεων myDATA
                @if ($clean)
                    <x-filament::badge color="success">Όλα εντάξει</x-filament::badge>
                @else
                    @if ($errorCount > 0)
                        <x-filament::badge color="danger">{{ $errorCount }} σφάλματα</x-filament::badge>
                    @endif
                    @if ($warnCount > 0)
                        <x-filament::badge color="warning">{{ $warnCount }} προειδοποιήσεις</x-filament::badge>
                    @endif
                @endif
            </span>
        </x-slot>
        <x-slot name="description">
            Τοπικός έλεγχος — δεν ρωτά το AADE. Διασταυρώνει τους τύπους παραστατικών &amp; τις κατηγορίες ΦΠΑ
            με τους πίνακες κωδικών της ΑΑΔΕ, ώστε να εντοπιστούν κενά ΠΡΙΝ απορρίψει μια υποβολή το myDATA.
            Κάθε εύρημα συνδέει στη ρύθμιση που το διορθώνει.
        </x-slot>

        {{-- Tenant readiness (provider / mode / credentials). --}}
        @if ($ready)
            <div class="flex items-start gap-2">
                <x-filament::icon :icon="$badge[$ready['status']]['icon']" @class([
                    'mt-0.5 h-5 w-5 shrink-0',
                    'text-success-500' => $ready['status'] === 'ok',
                    'text-warning-500' => $ready['status'] === 'warn',
                    'text-danger-500'  => $ready['status'] === 'error',
                ]) />
                <div class="text-sm">
                    @if (empty($ready['messages']))
                        <span class="text-gray-600 dark:text-gray-300">Πάροχος, λειτουργία και διαπιστευτήρια είναι έτοιμα.</span>
                    @else
                        <ul class="list-disc space-y-1 ps-4 text-gray-600 dark:text-gray-300">
                            @foreach ($ready['messages'] as $m)
                                <li>{{ $m }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>

    @php
        $table = function (array $rows, string $head, string $detailHead) use ($badge) {
            return compact('rows', 'head', 'detailHead', 'badge');
        };
        $sections = [
            ['title' => 'Τύποι παραστατικών', 'detailHead' => 'myDATA τύπος', 'rows' => $invoiceTypes,
             'empty' => 'Δεν έχουν οριστεί τύποι παραστατικών.'],
            ['title' => 'Κατηγορίες ΦΠΑ', 'detailHead' => 'Συντελεστής', 'rows' => $vatCategories,
             'empty' => 'Δεν έχουν οριστεί κατηγορίες ΦΠΑ.'],
        ];
    @endphp

    @foreach ($sections as $s)
        <x-filament::section>
            <x-slot name="heading">{{ $s['title'] }}</x-slot>

            @if (count($s['rows']) === 0)
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-inbox" class="h-5 w-5" />
                    <span>{{ $s['empty'] }}</span>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pr-4">Κατάσταση</th>
                                <th class="py-2 pr-4">Όνομα</th>
                                <th class="py-2 pr-4">{{ $s['detailHead'] }}</th>
                                <th class="py-2 pr-4">Ευρήματα</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($s['rows'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 align-top">
                                        <x-filament::badge :color="$badge[$row['status']]['color']" :icon="$badge[$row['status']]['icon']">
                                            {{ $badge[$row['status']]['label'] }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4 align-top">{{ $row['label'] }}</td>
                                    <td class="py-2 pr-4 align-top">
                                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] ?? '—' }}</span>
                                    </td>
                                    <td class="py-2 pr-4 align-top">
                                        @if (empty($row['messages']))
                                            <span class="text-gray-400">—</span>
                                        @else
                                            <ul class="space-y-1">
                                                @foreach ($row['messages'] as $m)
                                                    <li @class([
                                                        'text-danger-600 dark:text-danger-400' => $row['status'] === 'error',
                                                        'text-warning-600 dark:text-warning-400' => $row['status'] === 'warn',
                                                    ])>{{ $m }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right align-top whitespace-nowrap">
                                        @if ($row['url'] && $row['status'] !== 'ok')
                                            <a href="{{ $row['url'] }}"
                                               class="text-primary-600 hover:underline dark:text-primary-400">
                                                Διόρθωση →
                                            </a>
                                        @elseif ($row['url'])
                                            <a href="{{ $row['url'] }}"
                                               class="text-gray-400 hover:underline">
                                                Άνοιγμα
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
